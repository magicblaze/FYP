import argparse
import json
import os
from pathlib import Path
from typing import Dict, List, Optional

import faiss
import numpy as np
import torch
from PIL import Image
from transformers import CLIPModel, CLIPProcessor


def normalize(vectors: np.ndarray, eps: float = 1e-10) -> np.ndarray:
    norms = np.linalg.norm(vectors, axis=1, keepdims=True)
    return vectors / (norms + eps)


def _split_sql_tuples(values_sql: str) -> List[str]:
    tuples: List[str] = []
    in_quote = False
    escape = False
    depth = 0
    start = -1

    for i, ch in enumerate(values_sql):
        if in_quote:
            if escape:
                escape = False
            elif ch == "\\":
                escape = True
            elif ch == "'":
                in_quote = False
            continue

        if ch == "'":
            in_quote = True
        elif ch == "(":
            if depth == 0:
                start = i
            depth += 1
        elif ch == ")":
            depth -= 1
            if depth == 0 and start >= 0:
                tuples.append(values_sql[start : i + 1])
                start = -1

    return tuples


def _parse_sql_tuple(tuple_sql: str) -> List[Optional[str]]:
    inner = tuple_sql.strip()
    if inner.startswith("(") and inner.endswith(")"):
        inner = inner[1:-1]

    fields: List[Optional[str]] = []
    current = []
    in_quote = False
    escape = False

    def flush_field() -> None:
        raw = "".join(current).strip()
        if raw.upper() == "NULL" or raw == "":
            fields.append(None)
        else:
            fields.append(raw)

    i = 0
    while i < len(inner):
        ch = inner[i]
        if in_quote:
            if escape:
                current.append(ch)
                escape = False
            elif ch == "\\":
                escape = True
            elif ch == "'":
                in_quote = False
            else:
                current.append(ch)
        else:
            if ch == "'":
                in_quote = True
            elif ch == ",":
                flush_field()
                current = []
            else:
                current.append(ch)
        i += 1

    flush_field()
    return fields


def _extract_insert_rows(sql_text: str, table_name: str) -> List[dict]:
    import re

    pattern = re.compile(
        rf"INSERT\s+INTO\s+`{table_name}`\s*\((.*?)\)\s*VALUES\s*(.*?);",
        re.IGNORECASE | re.DOTALL,
    )

    rows: List[dict] = []
    for match in pattern.finditer(sql_text):
        cols_raw = match.group(1)
        values_raw = match.group(2)

        cols = [c.strip().strip("`") for c in cols_raw.split(",")]
        tuple_sql_list = _split_sql_tuples(values_raw)

        for tuple_sql in tuple_sql_list:
            values = _parse_sql_tuple(tuple_sql)
            if len(values) != len(cols):
                continue
            rows.append(dict(zip(cols, values)))
    return rows


def fetch_items_from_sql_dump(sql_path: Path) -> List[dict]:
    if not sql_path.exists():
        raise FileNotFoundError(f"SQL dump not found: {sql_path}")

    sql_text = sql_path.read_text(encoding="utf-8", errors="ignore")

    design_rows = _extract_insert_rows(sql_text, "Design")
    design_img_rows = _extract_insert_rows(sql_text, "DesignImage")
    product_rows = _extract_insert_rows(sql_text, "Product")
    product_img_rows = _extract_insert_rows(sql_text, "ProductColorImage")

    first_design_img: Dict[int, str] = {}
    for row in design_img_rows:
        design_id = int(row.get("designid") or 0)
        if design_id <= 0:
            continue
        image_name = row.get("image_filename")
        if not image_name:
            continue
        image_order = int(row.get("image_order") or 999999)

        prev = first_design_img.get(design_id)
        if prev is None:
            first_design_img[design_id] = image_name
        else:
            # Keep first by image_order; fallback preserve first encountered
            prev_rows = [r for r in design_img_rows if int(r.get("designid") or 0) == design_id and r.get("image_filename") == prev]
            prev_order = int(prev_rows[0].get("image_order") or 999999) if prev_rows else 999999
            if image_order < prev_order:
                first_design_img[design_id] = image_name

    first_product_img: Dict[int, str] = {}
    for row in product_img_rows:
        product_id = int(row.get("productid") or 0)
        if product_id <= 0:
            continue
        image_name = row.get("image")
        if not image_name:
            continue
        image_id = int(row.get("id") or 999999)

        prev = first_product_img.get(product_id)
        if prev is None:
            first_product_img[product_id] = image_name
        else:
            prev_rows = [r for r in product_img_rows if int(r.get("productid") or 0) == product_id and r.get("image") == prev]
            prev_id = int(prev_rows[0].get("id") or 999999) if prev_rows else 999999
            if image_id < prev_id:
                first_product_img[product_id] = image_name

    items: List[dict] = []

    for row in design_rows:
        design_id = int(row.get("designid") or 0)
        if design_id <= 0:
            continue
        image_name = first_design_img.get(design_id)
        items.append(
            {
                "item_id": design_id,
                "item_type": "design",
                "name": row.get("designName"),
                "description": row.get("description"),
                "tags": row.get("tag"),
                "price": float(row["expect_price"]) if row.get("expect_price") is not None else None,
                "likes": int(row["likes"]) if row.get("likes") is not None else 0,
                "image_path": f"uploads/designs/{image_name}" if image_name else None,
                "category": None,
                "material": None,
            }
        )

    for row in product_rows:
        product_id = int(row.get("productid") or 0)
        if product_id <= 0:
            continue
        raw_category = (row.get("category") or "").strip()
        category_lower = raw_category.lower()
        if category_lower not in {"furniture", "material"}:
            continue

        image_name = first_product_img.get(product_id)
        items.append(
            {
                "item_id": product_id,
                "item_type": category_lower,
                "name": row.get("pname"),
                "description": row.get("description"),
                "tags": None,
                "price": float(row["price"]) if row.get("price") is not None else None,
                "likes": int(row["likes"]) if row.get("likes") is not None else 0,
                "image_path": f"uploads/products/{image_name}" if image_name else None,
                "category": raw_category,
                "material": row.get("material"),
            }
        )

    return items


def fetch_items_from_mysql() -> List[dict]:
    import pymysql

    conn = pymysql.connect(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASSWORD", ""),
        database=os.getenv("DB_NAME", "fypdb"),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )

    items: List[dict] = []
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                    d.designid AS item_id,
                    'design' AS item_type,
                    d.designName AS name,
                    d.description AS description,
                    d.tag AS tags,
                    d.expect_price AS price,
                    d.likes AS likes,
                    CONCAT('uploads/designs/', di.image_filename) AS image_path,
                    NULL AS category,
                    NULL AS material
                FROM Design d
                LEFT JOIN (
                    SELECT di1.designid, di1.image_filename
                    FROM DesignImage di1
                    INNER JOIN (
                        SELECT designid, MIN(image_order) AS min_order
                        FROM DesignImage
                        GROUP BY designid
                    ) first_img ON first_img.designid = di1.designid AND first_img.min_order = di1.image_order
                ) di ON di.designid = d.designid
                """
            )
            items.extend(cur.fetchall())

            cur.execute(
                """
                SELECT
                    p.productid AS item_id,
                    LOWER(p.category) AS item_type,
                    p.pname AS name,
                    p.description AS description,
                    NULL AS tags,
                    p.price AS price,
                    p.likes AS likes,
                    CONCAT('uploads/products/', pci.image) AS image_path,
                    p.category AS category,
                    p.material AS material
                FROM Product p
                LEFT JOIN (
                    SELECT pci1.productid, pci1.image
                    FROM ProductColorImage pci1
                    INNER JOIN (
                        SELECT productid, MIN(id) AS min_id
                        FROM ProductColorImage
                        GROUP BY productid
                    ) first_img ON first_img.productid = pci1.productid AND first_img.min_id = pci1.id
                ) pci ON pci.productid = p.productid
                WHERE p.category IN ('Furniture', 'Material')
                """
            )
            items.extend(cur.fetchall())
    finally:
        conn.close()

    return items


def build_text_prompt(item: dict) -> str:
    parts = [
        f"type: {item.get('item_type', '')}",
        f"name: {item.get('name', '')}",
        f"description: {item.get('description', '')}",
        f"tags: {item.get('tags', '')}",
        f"category: {item.get('category', '')}",
        f"material: {item.get('material', '')}",
    ]
    return ". ".join([p for p in parts if p and not p.endswith(': ')])


def load_image(abs_path: Path) -> Image.Image | None:
    if not abs_path.exists() or not abs_path.is_file():
        return None


def export_recommend_sql(items: List[dict], sql_output_path: Path) -> None:
    sql_output_path.parent.mkdir(parents=True, exist_ok=True)

    lines: List[str] = []
    lines.append("DROP TABLE IF EXISTS `AIRecommendItem`;")
    lines.append(
        """
CREATE TABLE `AIRecommendItem` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `item_type` VARCHAR(32) NOT NULL,
  `item_id` INT NOT NULL,
  `name` VARCHAR(255) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `price` DECIMAL(10,2) DEFAULT NULL,
  `category` VARCHAR(64) DEFAULT NULL,
  `image_path` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_type`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
""".strip()
    )

    if items:
        values_sql = []
        for item in items:
            def esc(value: Optional[object]) -> str:
                if value is None:
                    return "NULL"
                if isinstance(value, (int, float)):
                    return str(value)
                text = str(value).replace("\\", "\\\\").replace("'", "\\'")
                return f"'{text}'"

            values_sql.append(
                "(" + ", ".join(
                    [
                        esc(item.get("item_type")),
                        esc(item.get("item_id")),
                        esc(item.get("name")),
                        esc(item.get("description")),
                        esc(item.get("price")),
                        esc(item.get("category")),
                        esc(item.get("image_path")),
                    ]
                ) + ")"
            )

        lines.append(
            "INSERT INTO `AIRecommendItem` (`item_type`, `item_id`, `name`, `description`, `price`, `category`, `image_path`) VALUES\n"
            + ",\n".join(values_sql)
            + ";"
        )

    sql_output_path.write_text("\n\n".join(lines) + "\n", encoding="utf-8")
    try:
        with Image.open(abs_path) as img:
            return img.convert("RGB")
    except Exception:
        return None


def main() -> None:
    parser = argparse.ArgumentParser(description="Train mixed recommendation index for design/furniture/material")
    parser.add_argument("--batch-size", type=int, default=32)
    parser.add_argument("--text-weight", type=float, default=0.35)
    parser.add_argument("--image-weight", type=float, default=0.65)
    parser.add_argument("--source", choices=["sql", "mysql"], default="sql")
    parser.add_argument("--sql-file", type=str, default="")
    parser.add_argument("--export-sql", type=str, default="")
    args = parser.parse_args()

    base_dir = Path(__file__).resolve().parent.parent
    project_root = base_dir.parent
    data_dir = base_dir / "data"
    data_dir.mkdir(parents=True, exist_ok=True)

    model_name = os.getenv("CLIP_MODEL", "openai/clip-vit-base-patch32")
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")

    print(f"[INFO] Loading model: {model_name} on {device}")
    processor = CLIPProcessor.from_pretrained(model_name)
    model = CLIPModel.from_pretrained(model_name).to(device)
    model.eval()

    if args.source == "sql":
        sql_file = Path(args.sql_file) if args.sql_file else (project_root / "FYPDB.sql")
        print(f"[INFO] Loading items from SQL dump: {sql_file}")
        items = fetch_items_from_sql_dump(sql_file)
    else:
        print("[INFO] Loading items from MySQL...")
        items = fetch_items_from_mysql()

    if not items:
        raise RuntimeError("No items found from selected source.")

    text_prompts = [build_text_prompt(item) for item in items]

    print(f"[INFO] Encoding text for {len(items)} items...")
    text_vectors: List[np.ndarray] = []
    for start in range(0, len(text_prompts), args.batch_size):
        batch = text_prompts[start : start + args.batch_size]
        inputs = processor(text=batch, return_tensors="pt", padding=True, truncation=True).to(device)
        with torch.no_grad():
            emb = model.get_text_features(**inputs)
        text_vectors.append(emb.cpu().numpy().astype(np.float32))
    text_emb = normalize(np.vstack(text_vectors))

    print(f"[INFO] Encoding images for {len(items)} items...")
    image_emb = np.zeros_like(text_emb, dtype=np.float32)
    has_image = np.zeros((len(items),), dtype=np.bool_)

    for start in range(0, len(items), args.batch_size):
        batch_items = items[start : start + args.batch_size]
        pil_images = []
        valid_positions = []

        for i, item in enumerate(batch_items):
            rel_image = item.get("image_path")
            if not rel_image:
                continue
            abs_image = project_root / str(rel_image)
            img = load_image(abs_image)
            if img is None:
                continue
            pil_images.append(img)
            valid_positions.append(i)

        if not pil_images:
            continue

        inputs = processor(images=pil_images, return_tensors="pt", padding=True).to(device)
        with torch.no_grad():
            emb = model.get_image_features(**inputs)
        vecs = normalize(emb.cpu().numpy().astype(np.float32))

        for local_pos, vec in zip(valid_positions, vecs):
            global_pos = start + local_pos
            image_emb[global_pos] = vec
            has_image[global_pos] = True

    print("[INFO] Combining text+image vectors...")
    combined = np.copy(text_emb)
    both_mask = has_image
    combined[both_mask] = args.text_weight * text_emb[both_mask] + args.image_weight * image_emb[both_mask]
    combined = normalize(combined)

    print("[INFO] Building FAISS index...")
    dim = combined.shape[1]
    index = faiss.IndexFlatIP(dim)
    index.add(combined.astype(np.float32))

    index_path = data_dir / "recommend.index"
    meta_path = data_dir / "recommend_metadata.json"

    faiss.write_index(index, str(index_path))

    output_items = []
    for item in items:
        output_items.append(
            {
                "item_type": (item.get("item_type") or "").lower(),
                "item_id": int(item.get("item_id") or 0),
                "name": item.get("name"),
                "description": item.get("description"),
                "price": float(item["price"]) if item.get("price") is not None else None,
                "category": item.get("category"),
                "image_path": item.get("image_path"),
            }
        )

    with meta_path.open("w", encoding="utf-8") as f:
        json.dump(output_items, f, ensure_ascii=False, indent=2)

    sql_export_path = Path(args.export_sql) if args.export_sql else (data_dir / "recommend_metadata.sql")
    export_recommend_sql(output_items, sql_export_path)

    count_design = sum(1 for i in output_items if i["item_type"] == "design")
    count_furniture = sum(1 for i in output_items if i["item_type"] == "furniture")
    count_material = sum(1 for i in output_items if i["item_type"] == "material")

    print("[OK] Recommendation training complete")
    print(f"[OK] Index: {index_path}")
    print(f"[OK] Metadata: {meta_path}")
    print(f"[OK] SQL metadata: {sql_export_path}")
    print(f"[OK] Counts => design={count_design}, furniture={count_furniture}, material={count_material}")


if __name__ == "__main__":
    main()
