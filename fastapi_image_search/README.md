# FastAPI Image Search Service

This service provides CLIP + FAISS image search endpoints that your PHP app can call.

## Endpoints

- `GET /health` — service status
- `POST /index/rebuild` — scan image folder, compute embeddings, rebuild FAISS index
- `POST /search/text` — text-to-image search
- `POST /search/image` — image-to-image search
- `POST /recommend/text` — recommend across design + furniture + material
- `POST /recommend/image` — image-based recommend across design + furniture + material
- `POST /search/image/recommend` — alias of image-based mixed recommendation

## Folder assumptions

By default it indexes images in:

- `../uploads/designs` (relative to `fastapi_image_search`)

You can override with environment variable `IMAGE_SEARCH_DIR`.

## Setup (Windows, PowerShell)

```powershell
cd c:\xampp\htdocs\FYP\fastapi_image_search
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
```

## Run server

```powershell
cd c:\xampp\htdocs\FYP\fastapi_image_search
.\.venv\Scripts\Activate.ps1
uvicorn app.main:app --host 127.0.0.1 --port 8001 --reload
```

Open docs at:

- `http://127.0.0.1:8001/docs`

## Build index first

```powershell
Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8001/index/rebuild"
```

## Train recommendation model

This builds a mixed FAISS index from local SQL dump data by default (`FYPDB.sql`):

- `Design` + first image from `DesignImage`
- `Product` where category is `Furniture` / `Material` + first image from `ProductColorImage`

Run:

```powershell
cd c:\xampp\htdocs\FYP\fastapi_image_search
C:\Users\boot\AppData\Local\Programs\Python\Python312\python.exe -m pip install -r requirements.txt
C:\Users\boot\AppData\Local\Programs\Python\Python312\python.exe -m app.train_recommender
```

Use a custom SQL dump file:

```powershell
C:\Users\boot\AppData\Local\Programs\Python\Python312\python.exe -m app.train_recommender --source sql --sql-file "c:\xampp\htdocs\FYP\FYPDB.sql"
```

If you still want MySQL mode:

```powershell
C:\Users\boot\AppData\Local\Programs\Python\Python312\python.exe -m app.train_recommender --source mysql
```

Generated files:

- `data/recommend.index`
- `data/recommend_metadata.json`
- `data/recommend_metadata.sql` (SQL table + inserts for trained recommendation data)

Optional DB env vars (defaults match your `config.php`):

- `DB_HOST` (default `127.0.0.1`)
- `DB_USER` (default `root`)
- `DB_PASSWORD` (default empty)
- `DB_NAME` (default `fypdb`)

## Example request (text search)

```powershell
$body = @{ query = "modern living room sofa"; top_k = 8 } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8001/search/text" -ContentType "application/json" -Body $body
```

## Example request (recommend text)

```powershell
$body = @{ query = "modern living room"; top_k = 10; item_types = @("design","furniture","material") } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8001/recommend/text" -ContentType "application/json" -Body $body
```

## Example request (recommend image)

```powershell
curl.exe -X POST "http://127.0.0.1:8001/recommend/image?top_k=8&item_types=design,furniture,material" -F "file=@c:/path/to/query.jpg"
```

Alias endpoint (same behavior):

```powershell
curl.exe -X POST "http://127.0.0.1:8001/search/image/recommend?top_k=8&item_types=design,furniture,material" -F "file=@c:/path/to/query.jpg"
```

## Example request (image search)

```powershell
curl.exe -X POST "http://127.0.0.1:8001/search/image?top_k=8" -F "file=@c:/path/to/query.jpg"
```

## PHP integration idea

From your PHP page (e.g., `design_dashboard.php`), call:

- `POST http://127.0.0.1:8001/search/text` with JSON `{ "query": "...", "top_k": 20 }`

Then use returned `image_path` list to prioritize design IDs in your SQL result ordering.

## Notes

- First run downloads CLIP model (~hundreds of MB).
- CPU works, but GPU is much faster.
- Rebuild index whenever new images are added to `uploads/designs`.
