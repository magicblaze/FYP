import io
import json
import os
from pathlib import Path
from threading import Lock
from typing import List

import faiss
import numpy as np
import torch
from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from PIL import Image
from pydantic import BaseModel, Field
from transformers import CLIPModel, CLIPProcessor


class TextSearchRequest(BaseModel):
    query: str = Field(..., min_length=1)
    top_k: int = Field(10, ge=1, le=100)


class SearchResult(BaseModel):
    image_path: str
    score: float


class SearchResponse(BaseModel):
    query_type: str
    top_k: int
    results: List[SearchResult]


class ImageSearchEngine:
    def __init__(self) -> None:
        self.base_dir = Path(__file__).resolve().parent.parent
        self.data_dir = self.base_dir / "data"
        self.image_dir = Path(os.getenv("IMAGE_SEARCH_DIR", str(self.base_dir.parent / "uploads" / "designs")))

        self.index_path = self.data_dir / "faiss.index"
        self.meta_path = self.data_dir / "metadata.json"

        self.model_name = os.getenv("CLIP_MODEL", "openai/clip-vit-base-patch32")
        self.device = torch.device("cuda" if torch.cuda.is_available() else "cpu")

        self.processor: CLIPProcessor | None = None
        self.model: CLIPModel | None = None
        self.index: faiss.Index | None = None
        self.image_paths: List[str] = []
        self.lock = Lock()

        self.data_dir.mkdir(parents=True, exist_ok=True)

    def load_model(self) -> None:
        if self.model is None or self.processor is None:
            self.processor = CLIPProcessor.from_pretrained(self.model_name)
            self.model = CLIPModel.from_pretrained(self.model_name).to(self.device)
            self.model.eval()

    @staticmethod
    def _normalize(vectors: np.ndarray, eps: float = 1e-10) -> np.ndarray:
        norms = np.linalg.norm(vectors, axis=1, keepdims=True)
        return vectors / (norms + eps)

    def _supported_images(self) -> List[Path]:
        exts = {".jpg", ".jpeg", ".png", ".webp", ".bmp"}
        if not self.image_dir.exists():
            return []
        paths: List[Path] = []
        for p in self.image_dir.rglob("*"):
            if p.is_file() and p.suffix.lower() in exts:
                paths.append(p)
        paths.sort()
        return paths

    def _embed_images(self, paths: List[Path], batch_size: int = 32) -> np.ndarray:
        if not paths:
            return np.zeros((0, 512), dtype=np.float32)

        assert self.processor is not None and self.model is not None
        all_embeddings: List[np.ndarray] = []

        for start in range(0, len(paths), batch_size):
            batch_paths = paths[start : start + batch_size]
            images: List[Image.Image] = []
            for p in batch_paths:
                with Image.open(p) as img:
                    images.append(img.convert("RGB"))

            inputs = self.processor(images=images, return_tensors="pt", padding=True).to(self.device)
            with torch.no_grad():
                batch_emb = self.model.get_image_features(**inputs)
            all_embeddings.append(batch_emb.cpu().numpy().astype(np.float32))

        embeddings = np.vstack(all_embeddings)
        return self._normalize(embeddings)

    def _embed_text(self, text: str) -> np.ndarray:
        assert self.processor is not None and self.model is not None
        inputs = self.processor(text=[text], return_tensors="pt", padding=True).to(self.device)
        with torch.no_grad():
            emb = self.model.get_text_features(**inputs)
        vec = emb.cpu().numpy().astype(np.float32)
        return self._normalize(vec)

    def _embed_query_image(self, image_bytes: bytes) -> np.ndarray:
        assert self.processor is not None and self.model is not None
        with Image.open(io.BytesIO(image_bytes)) as img:
            image = img.convert("RGB")

        inputs = self.processor(images=[image], return_tensors="pt", padding=True).to(self.device)
        with torch.no_grad():
            emb = self.model.get_image_features(**inputs)
        vec = emb.cpu().numpy().astype(np.float32)
        return self._normalize(vec)

    def save_index(self) -> None:
        if self.index is None:
            raise RuntimeError("Index is not built")
        faiss.write_index(self.index, str(self.index_path))
        with self.meta_path.open("w", encoding="utf-8") as f:
            json.dump({"image_paths": self.image_paths}, f, ensure_ascii=False, indent=2)

    def load_index(self) -> bool:
        if not self.index_path.exists() or not self.meta_path.exists():
            return False
        self.index = faiss.read_index(str(self.index_path))
        with self.meta_path.open("r", encoding="utf-8") as f:
            data = json.load(f)
        self.image_paths = data.get("image_paths", [])
        return self.index is not None and len(self.image_paths) > 0

    def rebuild_index(self) -> dict:
        with self.lock:
            self.load_model()
            paths = self._supported_images()
            if not paths:
                raise HTTPException(status_code=400, detail=f"No images found under: {self.image_dir}")

            embeddings = self._embed_images(paths)
            dim = embeddings.shape[1]
            index = faiss.IndexFlatIP(dim)
            index.add(embeddings)

            self.index = index
            self.image_paths = [str(p) for p in paths]
            self.save_index()

            return {
                "status": "ok",
                "image_count": len(self.image_paths),
                "index_path": str(self.index_path),
                "metadata_path": str(self.meta_path),
                "image_root": str(self.image_dir),
            }

    def ensure_ready(self) -> None:
        self.load_model()
        if self.index is None or not self.image_paths:
            loaded = self.load_index()
            if not loaded:
                raise HTTPException(
                    status_code=400,
                    detail="Index not loaded. Call /index/rebuild first.",
                )

    def search_vector(self, query_vector: np.ndarray, top_k: int) -> SearchResponse:
        assert self.index is not None
        distances, indices = self.index.search(query_vector.astype(np.float32), top_k)

        results: List[SearchResult] = []
        for idx, score in zip(indices[0].tolist(), distances[0].tolist()):
            if idx < 0 or idx >= len(self.image_paths):
                continue
            results.append(SearchResult(image_path=self.image_paths[idx], score=float(score)))

        return SearchResponse(query_type="vector", top_k=top_k, results=results)


engine = ImageSearchEngine()
app = FastAPI(title="Image Search API", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.get("/health")
def health() -> dict:
    return {
        "status": "ok",
        "device": str(engine.device),
        "model": engine.model_name,
        "image_root": str(engine.image_dir),
        "index_exists": engine.index_path.exists(),
    }


@app.post("/index/rebuild")
def rebuild_index() -> dict:
    return engine.rebuild_index()


@app.post("/search/text", response_model=SearchResponse)
def search_text(payload: TextSearchRequest) -> SearchResponse:
    engine.ensure_ready()
    query_vec = engine._embed_text(payload.query)
    result = engine.search_vector(query_vec, payload.top_k)
    result.query_type = "text"
    return result


@app.post("/search/image", response_model=SearchResponse)
async def search_image(top_k: int = 10, file: UploadFile = File(...)) -> SearchResponse:
    if top_k < 1 or top_k > 100:
        raise HTTPException(status_code=400, detail="top_k must be between 1 and 100")

    engine.ensure_ready()

    image_bytes = await file.read()
    if not image_bytes:
        raise HTTPException(status_code=400, detail="Empty file")

    query_vec = engine._embed_query_image(image_bytes)
    result = engine.search_vector(query_vec, top_k)
    result.query_type = "image"
    return result
