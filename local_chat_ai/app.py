from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import List, Dict, Any, Optional
import os
import re
import mysql.connector
from mysql.connector import Error

app = FastAPI(title="Local Chat Assistant")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"] ,
    allow_headers=["*"] ,
)


class AnswerRequest(BaseModel):
    question: str
    history: List[Dict[str, Any]] = []
    user: Dict[str, Any] = {}


def get_db_config() -> Dict[str, str]:
    return {
        "host": os.getenv("DB_HOST", "127.0.0.1"),
        "user": os.getenv("DB_USER", "root"),
        "password": os.getenv("DB_PASSWORD", ""),
        "database": os.getenv("DB_NAME", "fypdb"),
    }


def run_query(sql: str, params: tuple = ()) -> List[Dict[str, Any]]:
    cfg = get_db_config()
    conn = mysql.connector.connect(**cfg)
    try:
        cur = conn.cursor(dictionary=True)
        cur.execute(sql, params)
        rows = cur.fetchall()
        return rows
    finally:
        try:
            conn.close()
        except Exception:
            pass


def extract_order_id(question: str) -> Optional[int]:
    if not question:
        return None
    m = re.search(r"\border\s*#?\s*(\d+)\b", question, re.IGNORECASE)
    if m:
        return int(m.group(1))
    m = re.search(r"\borderid\s*#?\s*(\d+)\b", question, re.IGNORECASE)
    if m:
        return int(m.group(1))
    return None


def detect_intent(question: str) -> str:
    q = (question or "").lower()
    if any(k in q for k in ["hello", "hi", "hey"]):
        return "greeting"
    if "payment" in q or "pay" in q or "deposit" in q:
        return "payment"
    if "delivery" in q or "deliver" in q:
        return "delivery"
    if "schedule" in q or "date" in q:
        return "schedule"
    if "status" in q:
        return "status"
    if "order" in q:
        return "order"
    return "unknown"


def is_how_to_design_dashboard(question: str) -> bool:
    q = (question or "").strip().lower()
    if "how to" not in q:
        return False
    return "design dashboard" in q or "design_dashboard" in q or "designs dashboard" in q


def design_dashboard_help() -> str:
    return (
        "How to use the Design Dashboard:\n"
        "1) Use the search bar at the top to search by tag (example: living room, modern, kitchen).\n"
        "2) Open the Filters section to set a price range (Min/Max), pick a designer, and choose Sort By.\n"
        "3) Click Apply to update results. Use Clear to reset all filters.\n"
        "4) Browse the design cards (image, name, likes, price). Click a card to open details.\n"
        "5) If results look empty, clear filters or try a simpler tag."
    )


def orders_for_user(role: str, user_id: int) -> List[Dict[str, Any]]:
    if not user_id or not role:
        return []
    role_l = role.lower()
    if role_l == "client":
        return run_query("SELECT orderid, ostatus, odate FROM `Order` WHERE clientid=%s ORDER BY orderid DESC", (user_id,))
    if role_l == "designer":
        return run_query(
            "SELECT o.orderid, o.ostatus, o.odate FROM `Order` o JOIN Design d ON o.designid=d.designid WHERE d.designerid=%s ORDER BY o.orderid DESC",
            (user_id,)
        )
    if role_l == "manager":
        return run_query(
            "SELECT o.orderid, o.ostatus, o.odate FROM `Order` o JOIN Schedule s ON o.orderid=s.orderid WHERE s.managerid=%s ORDER BY o.orderid DESC",
            (user_id,)
        )
    if role_l == "supplier":
        return run_query("SELECT orderid, ostatus, odate FROM `Order` WHERE supplierid=%s ORDER BY orderid DESC", (user_id,))
    if role_l == "contractor" or role_l == "contractors":
        return run_query(
            "SELECT o.orderid, o.ostatus, o.odate FROM `Order` o JOIN Order_Contractors oc ON o.orderid=oc.orderid WHERE oc.contractorid=%s ORDER BY o.orderid DESC",
            (user_id,)
        )
    return []


def user_can_access_order(role: str, user_id: int, order_id: int) -> bool:
    if not order_id:
        return False
    role_l = (role or "").lower()
    if role_l == "client":
        rows = run_query("SELECT orderid FROM `Order` WHERE orderid=%s AND clientid=%s", (order_id, user_id))
        return bool(rows)
    if role_l == "designer":
        rows = run_query(
            "SELECT o.orderid FROM `Order` o JOIN Design d ON o.designid=d.designid WHERE o.orderid=%s AND d.designerid=%s",
            (order_id, user_id),
        )
        return bool(rows)
    if role_l == "manager":
        rows = run_query(
            "SELECT o.orderid FROM `Order` o JOIN Schedule s ON o.orderid=s.orderid WHERE o.orderid=%s AND s.managerid=%s",
            (order_id, user_id),
        )
        return bool(rows)
    if role_l == "supplier":
        rows = run_query("SELECT orderid FROM `Order` WHERE orderid=%s AND supplierid=%s", (order_id, user_id))
        return bool(rows)
    if role_l == "contractor" or role_l == "contractors":
        rows = run_query(
            "SELECT o.orderid FROM `Order` o JOIN Order_Contractors oc ON o.orderid=oc.orderid WHERE o.orderid=%s AND oc.contractorid=%s",
            (order_id, user_id),
        )
        return bool(rows)
    return False


def summarize_orders(rows: List[Dict[str, Any]]) -> str:
    if not rows:
        return "No orders found."
    parts = []
    for r in rows[:5]:
        parts.append(f"#{r['orderid']} - {r['ostatus']}")
    more = "" if len(rows) <= 5 else f" (+{len(rows) - 5} more)"
    return "Your orders: " + ", ".join(parts) + more


def answer_with_db(question: str, user: Dict[str, Any]) -> str:
    q = (question or "").strip()
    if not q:
        return "Please ask a question."
    if is_how_to_design_dashboard(q):
        return design_dashboard_help()
    role = (user.get("type") or "").strip().lower()
    user_id = int(user.get("id") or 0)
    intent = detect_intent(q)
    order_id = extract_order_id(q)

    if intent == "greeting":
        return "Hi. Ask me about your orders, payments, delivery, or schedule."

    if intent in ["order", "status", "payment", "delivery", "schedule"] and not order_id:
        rows = orders_for_user(role, user_id)
        if not rows:
            return "No orders found for your account."
        return summarize_orders(rows) + "\nAsk about a specific order like: 'order 1 status'."

    if order_id:
        if not user_can_access_order(role, user_id, order_id):
            return "You do not have access to that order or it was not found."

        if intent in ["status", "order"]:
            rows = run_query("SELECT orderid, ostatus, odate, supplier_status FROM `Order` WHERE orderid=%s", (order_id,))
            if not rows:
                return "Order not found."
            r = rows[0]
            s = r.get("supplier_status") or ""
            s_text = f", supplier status: {s}" if s else ""
            return f"Order #{r['orderid']} status: {r['ostatus']} (date: {r['odate']}){s_text}."

        if intent == "payment":
            rows = run_query(
                "SELECT op.total_amount_due, op.total_amount_paid, op.payment_status "
                "FROM `Order` o JOIN OrderPayment op ON o.payment_id=op.payment_id WHERE o.orderid=%s",
                (order_id,),
            )
            if not rows:
                return "No payment record found for that order."
            r = rows[0]
            return (
                f"Order #{order_id} payment status: {r['payment_status']}. "
                f"Paid: {r['total_amount_paid']}, Due: {r['total_amount_due']}"
            )

        if intent == "delivery":
            rows = run_query(
                "SELECT productid, quantity, deliverydate, status, color FROM OrderDelivery WHERE orderid=%s",
                (order_id,),
            )
            if not rows:
                return "No delivery records found for that order."
            parts = []
            for r in rows:
                parts.append(
                    f"Product {r['productid']} x{r['quantity']} - {r['status']} ({r['deliverydate']})"
                )
            return "Delivery for order #{0}: ".format(order_id) + "; ".join(parts)

        if intent == "schedule":
            rows = run_query(
                "SELECT DesignFinishDate, OrderFinishDate, construction_start_date, construction_end_date, construction_date_status "
                "FROM Schedule WHERE orderid=%s",
                (order_id,),
            )
            if not rows:
                return "No schedule found for that order."
            r = rows[0]
            return (
                f"Order #{order_id} schedule: design finish {r['DesignFinishDate']}, "
                f"order finish {r['OrderFinishDate']}, construction {r['construction_start_date']} to {r['construction_end_date']} "
                f"(status: {r['construction_date_status']})."
            )

    return "Ask about order status, payment, delivery, or schedule. Example: 'order 1 payment'."


@app.post("/answer")
async def answer(req: AnswerRequest):
    try:
        return {"answer": answer_with_db(req.question, req.user or {})}
    except Error:
        return {"answer": "Database is unavailable. Please try again later."}
    except Exception:
        return {"answer": "Sorry, I could not process that question."}
