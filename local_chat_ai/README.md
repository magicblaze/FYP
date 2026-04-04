# Local Chat Assistant

Minimal local HTTP service for the chat widget.

## Setup (Windows, PowerShell)

```powershell
cd c:\xampp\htdocs\FYP\local_chat_ai
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
```

## Run

```powershell
cd c:\xampp\htdocs\FYP\local_chat_ai
.\.venv\Scripts\Activate.ps1
uvicorn app:app --host 127.0.0.1 --port 8010 --reload
```

## API

POST http://127.0.0.1:8010/answer

Request JSON:

```json
{
  "question": "Where is my order?",
  "history": [],
  "user": {"id": 1, "type": "client", "name": "Alice"}
}
```

Response JSON:

```json
{
  "answer": "For order status, open your order details page and check the status timeline."
}
```

## Database

This service reads the app database to answer order/payment/delivery/schedule questions.

Set these environment variables if your DB credentials differ:

- `DB_HOST` (default `127.0.0.1`)
- `DB_USER` (default `root`)
- `DB_PASSWORD` (default empty)
- `DB_NAME` (default `fypdb`)
