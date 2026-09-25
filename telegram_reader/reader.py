#!/usr/bin/env python3
from __future__ import annotations

import asyncio
import json
import os
import re
import signal
import sqlite3
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

from telethon import TelegramClient, events, utils
from telethon.errors import (
    PhoneCodeExpiredError,
    PhoneCodeInvalidError,
    PhoneNumberInvalidError,
    SessionPasswordNeededError,
)
from telethon.tl.functions.messages import GetDialogFiltersRequest


STATE_DIR = Path(os.environ.get("TELEGRAM_READER_STATE_DIR", "/var/lib/zampolit73-telegram-reader"))
SOCKET_PATH = Path(os.environ.get("TELEGRAM_READER_SOCKET", "/run/zampolit73-telegram-reader/reader.sock"))
SYNC_SECONDS = max(60, int(os.environ.get("TELEGRAM_READER_SYNC_SECONDS", "300")))
API_ID = int(os.environ["TELEGRAM_READER_API_ID"])
API_HASH = os.environ["TELEGRAM_READER_API_HASH"]
SESSION_PATH = STATE_DIR / "reader"
CORPUS_PATH = STATE_DIR / "corpus.sqlite3"

VACANCY_MARKERS = (
    "вакан",
    "требуется",
    "ищем",
    "ищет",
    "позици",
    "разработчик",
    "developer",
    "engineer",
    "аналитик",
    "architect",
    "qa",
    "devops",
    "ставка",
    "аутстаф",
    "outstaff",
    "требования",
    "requirements",
)

TECH_MARKERS = (
    "java",
    "kotlin",
    "python",
    "javascript",
    "typescript",
    "react",
    "vue",
    "angular",
    "node",
    "php",
    "laravel",
    "spring",
    "kafka",
    "camunda",
    "postgres",
    "oracle",
    "redis",
    "docker",
    "kubernetes",
    "clickhouse",
    "elasticsearch",
    "rabbitmq",
    ".net",
    "c#",
    "golang",
    "fastapi",
    "django",
    "sap",
)


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def safe_title(value: Any) -> str:
    if isinstance(value, str):
        return value
    text = getattr(value, "text", None)
    return str(text if text is not None else value)


def vacancy_like(text: str) -> bool:
    normalized = re.sub(r"\s+", " ", text.lower()).strip()
    if len(normalized) < 70:
        return False

    vacancy_hits = sum(1 for marker in VACANCY_MARKERS if marker in normalized)
    tech_hits = sum(1 for marker in TECH_MARKERS if marker in normalized)

    if vacancy_hits >= 1 and len(normalized) >= 100:
        return True

    if tech_hits >= 2 and len(normalized) >= 130:
        return True

    return len(normalized) >= 260 and (vacancy_hits + tech_hits) >= 1


class CorpusStore:
    def __init__(self, path: Path) -> None:
        self.path = path
        self.connection = sqlite3.connect(path, timeout=20, check_same_thread=False)
        self.connection.row_factory = sqlite3.Row
        self.fts_enabled = False
        self._migrate()

    def _migrate(self) -> None:
        self.connection.executescript(
            """
            PRAGMA journal_mode=WAL;
            PRAGMA synchronous=NORMAL;

            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT
            );

            CREATE TABLE IF NOT EXISTS chats (
                peer_id INTEGER PRIMARY KEY,
                title TEXT NOT NULL,
                username TEXT,
                folder_id INTEGER,
                active INTEGER NOT NULL DEFAULT 1,
                backfilled_at TEXT,
                last_sync_at TEXT,
                last_message_id INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                peer_id INTEGER NOT NULL,
                message_id INTEGER NOT NULL,
                message_date TEXT NOT NULL,
                edit_date TEXT,
                text TEXT NOT NULL,
                source_link TEXT,
                deleted INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(peer_id, message_id)
            );

            CREATE INDEX IF NOT EXISTS messages_peer_message_idx
                ON messages(peer_id, message_id);
            CREATE INDEX IF NOT EXISTS messages_date_idx
                ON messages(message_date);
            """
        )

        try:
            self.connection.executescript(
                """
                CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts
                    USING fts5(text, content='messages', content_rowid='id', tokenize='unicode61 remove_diacritics 2');

                CREATE TRIGGER IF NOT EXISTS messages_ai AFTER INSERT ON messages BEGIN
                    INSERT INTO messages_fts(rowid, text) VALUES (new.id, new.text);
                END;

                CREATE TRIGGER IF NOT EXISTS messages_ad AFTER DELETE ON messages BEGIN
                    INSERT INTO messages_fts(messages_fts, rowid, text)
                    VALUES ('delete', old.id, old.text);
                END;

                CREATE TRIGGER IF NOT EXISTS messages_au AFTER UPDATE OF text ON messages BEGIN
                    INSERT INTO messages_fts(messages_fts, rowid, text)
                    VALUES ('delete', old.id, old.text);
                    INSERT INTO messages_fts(rowid, text) VALUES (new.id, new.text);
                END;
                """
            )
            self.fts_enabled = True
        except sqlite3.OperationalError:
            self.fts_enabled = False

        self.connection.commit()

    def setting(self, key: str) -> str | None:
        row = self.connection.execute(
            "SELECT value FROM settings WHERE key = ?",
            (key,),
        ).fetchone()
        return None if row is None else row["value"]

    def set_setting(self, key: str, value: str | None) -> None:
        if value is None:
            self.connection.execute("DELETE FROM settings WHERE key = ?", (key,))
        else:
            self.connection.execute(
                """
                INSERT INTO settings(key, value) VALUES (?, ?)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value
                """,
                (key, value),
            )
        self.connection.commit()

    def upsert_chat(
        self,
        peer_id: int,
        title: str,
        username: str | None,
        folder_id: int,
        active: bool = True,
    ) -> None:
        self.connection.execute(
            """
            INSERT INTO chats(peer_id, title, username, folder_id, active)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(peer_id) DO UPDATE SET
                title = excluded.title,
                username = excluded.username,
                folder_id = excluded.folder_id,
                active = excluded.active
            """,
            (peer_id, title, username, folder_id, 1 if active else 0),
        )
        self.connection.commit()

    def deactivate_other_chats(self, active_peer_ids: list[int]) -> None:
        if not active_peer_ids:
            self.connection.execute("UPDATE chats SET active = 0")
        else:
            placeholders = ",".join("?" for _ in active_peer_ids)
            self.connection.execute(
                f"UPDATE chats SET active = 0 WHERE peer_id NOT IN ({placeholders})",
                active_peer_ids,
            )
        self.connection.commit()

    def chat(self, peer_id: int) -> sqlite3.Row | None:
        return self.connection.execute(
            "SELECT * FROM chats WHERE peer_id = ?",
            (peer_id,),
        ).fetchone()

    def active_peer_ids(self) -> set[int]:
        rows = self.connection.execute(
            "SELECT peer_id FROM chats WHERE active = 1"
        ).fetchall()
        return {int(row["peer_id"]) for row in rows}

    def mark_chat_synced(self, peer_id: int, last_message_id: int, backfilled: bool = False) -> None:
        if backfilled:
            self.connection.execute(
                """
                UPDATE chats
                SET last_sync_at = ?, backfilled_at = COALESCE(backfilled_at, ?),
                    last_message_id = MAX(last_message_id, ?)
                WHERE peer_id = ?
                """,
                (utc_now(), utc_now(), last_message_id, peer_id),
            )
        else:
            self.connection.execute(
                """
                UPDATE chats
                SET last_sync_at = ?, last_message_id = MAX(last_message_id, ?)
                WHERE peer_id = ?
                """,
                (utc_now(), last_message_id, peer_id),
            )
        self.connection.commit()

    def upsert_message(
        self,
        peer_id: int,
        message_id: int,
        message_date: str,
        edit_date: str | None,
        text: str,
        source_link: str | None,
    ) -> None:
        now = utc_now()
        self.connection.execute(
            """
            INSERT INTO messages(
                peer_id, message_id, message_date, edit_date, text,
                source_link, deleted, created_at, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)
            ON CONFLICT(peer_id, message_id) DO UPDATE SET
                message_date = excluded.message_date,
                edit_date = excluded.edit_date,
                text = excluded.text,
                source_link = excluded.source_link,
                deleted = 0,
                updated_at = excluded.updated_at
            """,
            (
                peer_id,
                message_id,
                message_date,
                edit_date,
                text,
                source_link,
                now,
                now,
            ),
        )
        self.connection.commit()

    def remove_message(self, peer_id: int, message_id: int) -> None:
        self.connection.execute(
            "DELETE FROM messages WHERE peer_id = ? AND message_id = ?",
            (peer_id, message_id),
        )
        self.connection.commit()

    def mark_deleted(self, peer_id: int, message_ids: list[int]) -> None:
        if not message_ids:
            return
        placeholders = ",".join("?" for _ in message_ids)
        self.connection.execute(
            f"""
            UPDATE messages
            SET deleted = 1, updated_at = ?
            WHERE peer_id = ? AND message_id IN ({placeholders})
            """,
            [utc_now(), peer_id, *message_ids],
        )
        self.connection.commit()

    def stats(self) -> dict[str, Any]:
        chat_count = self.connection.execute(
            "SELECT COUNT(*) AS count FROM chats WHERE active = 1"
        ).fetchone()["count"]
        indexed_count = self.connection.execute(
            "SELECT COUNT(*) AS count FROM messages WHERE deleted = 0"
        ).fetchone()["count"]
        last_sync = self.connection.execute(
            "SELECT MAX(last_sync_at) AS value FROM chats WHERE active = 1"
        ).fetchone()["value"]

        return {
            "chat_count": int(chat_count),
            "indexed_message_count": int(indexed_count),
            "last_sync_at": last_sync,
            "fts_enabled": self.fts_enabled,
        }

    def search(self, query: str, limit: int = 20) -> list[dict[str, Any]]:
        limit = max(1, min(limit, 50))
        terms = [
            term
            for term in re.findall(r"[\w#+.\-]{3,}", query.lower(), flags=re.UNICODE)
            if len(term) >= 3
        ][:12]

        if not terms:
            return []

        if self.fts_enabled:
            expression = " OR ".join(f'"{term.replace(chr(34), "")}"' for term in terms)
            rows = self.connection.execute(
                """
                SELECT
                    m.peer_id,
                    m.message_id,
                    m.message_date,
                    m.text,
                    m.source_link,
                    c.title AS chat_title,
                    bm25(messages_fts) AS rank
                FROM messages_fts
                JOIN messages m ON m.id = messages_fts.rowid
                JOIN chats c ON c.peer_id = m.peer_id
                WHERE messages_fts MATCH ?
                  AND m.deleted = 0
                  AND c.active = 1
                ORDER BY rank ASC, m.message_date DESC
                LIMIT ?
                """,
                (expression, limit),
            ).fetchall()
        else:
            clauses = " OR ".join("LOWER(m.text) LIKE ?" for _ in terms)
            params = [f"%{term}%" for term in terms]
            rows = self.connection.execute(
                f"""
                SELECT
                    m.peer_id,
                    m.message_id,
                    m.message_date,
                    m.text,
                    m.source_link,
                    c.title AS chat_title,
                    0 AS rank
                FROM messages m
                JOIN chats c ON c.peer_id = m.peer_id
                WHERE ({clauses})
                  AND m.deleted = 0
                  AND c.active = 1
                ORDER BY m.message_date DESC
                LIMIT ?
                """,
                [*params, limit],
            ).fetchall()

        return [
            {
                "peer_id": int(row["peer_id"]),
                "message_id": int(row["message_id"]),
                "message_date": row["message_date"],
                "text": row["text"],
                "source_link": row["source_link"],
                "chat_title": row["chat_title"],
                "rank": float(row["rank"] or 0),
            }
            for row in rows
        ]


class ReaderDaemon:
    def __init__(self) -> None:
        os.umask(0o077)
        STATE_DIR.mkdir(parents=True, exist_ok=True)
        os.chmod(STATE_DIR, 0o700)

        self.store = CorpusStore(CORPUS_PATH)
        self.client = TelegramClient(str(SESSION_PATH), API_ID, API_HASH)
        self.pending_phone: str | None = None
        self.pending_phone_code_hash: str | None = None
        self.auth_state = "unknown"
        self.last_error: str | None = None
        self.sync_running = False
        self.sync_lock = asyncio.Lock()
        self.connect_lock = asyncio.Lock()
        self.selected_peer_ids: set[int] = self.store.active_peer_ids()
        self.server: asyncio.AbstractServer | None = None

        self.client.add_event_handler(self._on_new_message, events.NewMessage)
        self.client.add_event_handler(self._on_edited_message, events.MessageEdited)
        self.client.add_event_handler(self._on_deleted_message, events.MessageDeleted)

    async def start(self) -> None:
        SOCKET_PATH.parent.mkdir(parents=True, exist_ok=True)
        if SOCKET_PATH.exists():
            SOCKET_PATH.unlink()

        self.server = await asyncio.start_unix_server(self._handle_rpc, path=str(SOCKET_PATH))
        os.chmod(SOCKET_PATH, 0o660)

        asyncio.create_task(self._connect_telegram())
        asyncio.create_task(self._sync_loop())

    async def _connect_telegram(self) -> bool:
        if self.client.is_connected():
            return True

        async with self.connect_lock:
            if self.client.is_connected():
                return True

            try:
                await asyncio.wait_for(self.client.connect(), timeout=12)
                authorized = await asyncio.wait_for(
                    self.client.is_user_authorized(),
                    timeout=8,
                )
                self.auth_state = "authorized" if authorized else "not_authorized"
                self.last_error = None
                return True
            except Exception as exc:  # noqa: BLE001
                self.auth_state = "connection_error"
                self.last_error = (
                    "MTProto connection failed: "
                    + type(exc).__name__
                    + (f": {str(exc)[:180]}" if str(exc) else "")
                )
                return False

    async def _require_connection(self) -> None:
        if not await self._connect_telegram():
            raise ValueError(
                "Reader запущен, но VPS пока не подключился к Telegram MTProto. "
                "Проверь сетевую диагностику."
            )

    async def close(self) -> None:
        if self.server is not None:
            self.server.close()
            await self.server.wait_closed()

        if self.client.is_connected():
            await self.client.disconnect()

        if SOCKET_PATH.exists():
            SOCKET_PATH.unlink()

    async def status(self) -> dict[str, Any]:
        connected = self.client.is_connected()
        authorized = self.auth_state == "authorized"
        account = None

        if connected:
            try:
                authorized = await asyncio.wait_for(
                    self.client.is_user_authorized(),
                    timeout=5,
                )
                self.auth_state = "authorized" if authorized else "not_authorized"
            except Exception as exc:  # noqa: BLE001
                self.last_error = f"Authorization status failed: {type(exc).__name__}"

        if connected and authorized:
            try:
                me = await asyncio.wait_for(self.client.get_me(), timeout=8)
                account = {
                    "username": getattr(me, "username", None),
                    "first_name": getattr(me, "first_name", None),
                }
            except Exception as exc:  # noqa: BLE001
                self.last_error = f"Account status failed: {type(exc).__name__}"

        selected_folder_id = self.store.setting("selected_folder_id")
        selected_folder_title = self.store.setting("selected_folder_title")

        return {
            "connected": connected,
            "authorized": authorized,
            "auth_state": self.auth_state,
            "account": account,
            "selected_folder": (
                {
                    "id": int(selected_folder_id),
                    "title": selected_folder_title or f"Folder {selected_folder_id}",
                }
                if selected_folder_id is not None
                else None
            ),
            "sync_running": self.sync_running,
            "last_error": self.last_error,
            **self.store.stats(),
        }

    async def request_code(self, phone: str) -> dict[str, Any]:
        phone = re.sub(r"[^+0-9]", "", phone.strip())

        if not re.fullmatch(r"\+[1-9][0-9]{7,14}", phone):
            raise ValueError("Номер должен быть в международном формате, например +79991234567.")

        await self._require_connection()

        if await self.client.is_user_authorized():
            self.auth_state = "authorized"
            return {"authorized": True, "auth_state": self.auth_state}

        try:
            sent = await self.client.send_code_request(phone)
        except PhoneNumberInvalidError as exc:
            raise ValueError("Telegram не принял этот номер телефона.") from exc

        self.pending_phone = phone
        self.pending_phone_code_hash = sent.phone_code_hash
        self.auth_state = "code_sent"
        self.last_error = None

        return {"authorized": False, "auth_state": self.auth_state}

    async def submit_code(self, code: str) -> dict[str, Any]:
        await self._require_connection()

        if not self.pending_phone or not self.pending_phone_code_hash:
            raise ValueError("Сначала запроси новый код Telegram.")

        code = re.sub(r"\D", "", code)

        if not re.fullmatch(r"[0-9]{4,8}", code):
            raise ValueError("Проверь код Telegram.")

        try:
            await self.client.sign_in(
                phone=self.pending_phone,
                code=code,
                phone_code_hash=self.pending_phone_code_hash,
            )
        except SessionPasswordNeededError:
            self.auth_state = "password_required"
            return {"authorized": False, "auth_state": self.auth_state}
        except PhoneCodeInvalidError as exc:
            raise ValueError("Telegram сообщил, что код неверный.") from exc
        except PhoneCodeExpiredError as exc:
            self.pending_phone = None
            self.pending_phone_code_hash = None
            self.auth_state = "not_authorized"
            raise ValueError("Код Telegram истёк. Запроси новый.") from exc

        self._clear_pending_auth()
        self.auth_state = "authorized"
        self.last_error = None

        return {"authorized": True, "auth_state": self.auth_state}

    async def submit_password(self, password: str) -> dict[str, Any]:
        await self._require_connection()

        if self.auth_state != "password_required":
            raise ValueError("Telegram сейчас не ожидает пароль 2FA.")

        if not password:
            raise ValueError("Введите пароль Telegram 2FA.")

        await self.client.sign_in(password=password)
        self._clear_pending_auth()
        self.auth_state = "authorized"
        self.last_error = None

        return {"authorized": True, "auth_state": self.auth_state}

    async def folders(self) -> list[dict[str, Any]]:
        await self._require_connection()
        self._ensure_authorized()
        filters = await self._dialog_filters()
        result = []

        for item in filters:
            folder_id = getattr(item, "id", None)
            if folder_id is None:
                continue

            included = self._filter_peer_ids(item)
            result.append(
                {
                    "id": int(folder_id),
                    "title": safe_title(getattr(item, "title", f"Folder {folder_id}")),
                    "explicit_chat_count": len(included),
                }
            )

        return result

    async def select_folder(self, folder_id: int) -> dict[str, Any]:
        await self._require_connection()
        self._ensure_authorized()
        filters = await self._dialog_filters()
        selected = next(
            (item for item in filters if int(getattr(item, "id", -1)) == folder_id),
            None,
        )

        if selected is None:
            raise ValueError("Эта Telegram-папка больше не найдена.")

        title = safe_title(getattr(selected, "title", f"Folder {folder_id}"))
        peer_ids = self._filter_peer_ids(selected)

        if not peer_ids:
            raise ValueError(
                "В этой папке нет явно добавленных чатов. Для MVP выбери папку, "
                "где рабочие чаты добавлены вручную."
            )

        self.store.set_setting("selected_folder_id", str(folder_id))
        self.store.set_setting("selected_folder_title", title)
        asyncio.create_task(self.sync_selected_folder(force_backfill=True))

        return {
            "selected_folder": {
                "id": folder_id,
                "title": title,
                "explicit_chat_count": len(peer_ids),
            }
        }

    async def sync_now(self) -> dict[str, Any]:
        await self._require_connection()
        self._ensure_authorized()
        if self.store.setting("selected_folder_id") is None:
            raise ValueError("Сначала выбери рабочую Telegram-папку.")

        asyncio.create_task(self.sync_selected_folder(force_backfill=False))

        return {"accepted": True}

    async def sync_selected_folder(self, force_backfill: bool = False) -> None:
        if self.sync_lock.locked():
            return

        async with self.sync_lock:
            self.sync_running = True
            try:
                folder_id_value = self.store.setting("selected_folder_id")
                if folder_id_value is None:
                    return

                folder_id = int(folder_id_value)
                filters = await self._dialog_filters()
                selected = next(
                    (item for item in filters if int(getattr(item, "id", -1)) == folder_id),
                    None,
                )

                if selected is None:
                    self.last_error = "Выбранная Telegram-папка больше не найдена."
                    return

                selected_peer_ids = self._filter_peer_ids(selected)
                dialogs = await self.client.get_dialogs(limit=None)
                dialog_map = {
                    int(utils.get_peer_id(dialog.entity)): dialog
                    for dialog in dialogs
                }

                active_peer_ids = [
                    peer_id for peer_id in selected_peer_ids if peer_id in dialog_map
                ]
                self.store.deactivate_other_chats(active_peer_ids)
                self.selected_peer_ids = set(active_peer_ids)

                for peer_id in active_peer_ids:
                    dialog = dialog_map[peer_id]
                    entity = dialog.entity
                    title = (
                        getattr(dialog, "name", None)
                        or getattr(entity, "title", None)
                        or getattr(entity, "first_name", None)
                        or str(peer_id)
                    )
                    username = getattr(entity, "username", None)

                    self.store.upsert_chat(
                        peer_id=peer_id,
                        title=str(title),
                        username=username,
                        folder_id=folder_id,
                        active=True,
                    )

                    chat = self.store.chat(peer_id)
                    needs_backfill = (
                        force_backfill
                        or chat is None
                        or not chat["backfilled_at"]
                    )

                    if needs_backfill:
                        await self._backfill_chat(entity, peer_id, username)
                    else:
                        await self._sync_chat(entity, peer_id, username)

                self.last_error = None
            except Exception as exc:  # noqa: BLE001
                self.last_error = f"{type(exc).__name__}: {str(exc)[:240]}"
            finally:
                self.sync_running = False

    async def _backfill_chat(self, entity: Any, peer_id: int, username: str | None) -> None:
        cutoff = datetime.now(timezone.utc) - timedelta(days=90)
        max_message_id = 0

        async for message in self.client.iter_messages(entity):
            if message.date and message.date < cutoff:
                break

            max_message_id = max(max_message_id, int(message.id))
            self._persist_message(peer_id, username, message)

        self.store.mark_chat_synced(peer_id, max_message_id, backfilled=True)

    async def _sync_chat(self, entity: Any, peer_id: int, username: str | None) -> None:
        chat = self.store.chat(peer_id)
        last_message_id = int(chat["last_message_id"] if chat else 0)
        max_message_id = last_message_id

        async for message in self.client.iter_messages(entity, min_id=last_message_id):
            max_message_id = max(max_message_id, int(message.id))
            self._persist_message(peer_id, username, message)

        recent = await self.client.get_messages(entity, limit=60)
        for message in recent:
            if message is None:
                continue
            max_message_id = max(max_message_id, int(message.id))
            self._persist_message(peer_id, username, message)

        self.store.mark_chat_synced(peer_id, max_message_id, backfilled=False)

    def _persist_message(self, peer_id: int, username: str | None, message: Any) -> None:
        text = (getattr(message, "message", None) or "").strip()

        if not text:
            return

        if not vacancy_like(text):
            self.store.remove_message(peer_id, int(message.id))
            return

        message_date = getattr(message, "date", None) or datetime.now(timezone.utc)
        edit_date = getattr(message, "edit_date", None)
        source_link = (
            f"https://t.me/{username}/{message.id}"
            if username
            else None
        )

        self.store.upsert_message(
            peer_id=peer_id,
            message_id=int(message.id),
            message_date=message_date.astimezone(timezone.utc).isoformat(),
            edit_date=(
                edit_date.astimezone(timezone.utc).isoformat()
                if edit_date
                else None
            ),
            text=text,
            source_link=source_link,
        )

    async def _on_new_message(self, event: Any) -> None:
        peer_id = int(getattr(event, "chat_id", 0) or 0)
        if peer_id not in self.selected_peer_ids:
            return

        chat = self.store.chat(peer_id)
        username = chat["username"] if chat else None
        self._persist_message(peer_id, username, event.message)

    async def _on_edited_message(self, event: Any) -> None:
        await self._on_new_message(event)

    async def _on_deleted_message(self, event: Any) -> None:
        peer_id = int(getattr(event, "chat_id", 0) or 0)
        if peer_id not in self.selected_peer_ids:
            return

        ids = [int(value) for value in getattr(event, "deleted_ids", [])]
        self.store.mark_deleted(peer_id, ids)

    async def _sync_loop(self) -> None:
        while True:
            try:
                connected = await self._connect_telegram()
                if (
                    connected
                    and self.auth_state == "authorized"
                    and self.store.setting("selected_folder_id") is not None
                ):
                    await self.sync_selected_folder(force_backfill=False)
            except Exception as exc:  # noqa: BLE001
                self.last_error = f"{type(exc).__name__}: {str(exc)[:240]}"

            await asyncio.sleep(SYNC_SECONDS)

    async def _dialog_filters(self) -> list[Any]:
        response = await self.client(GetDialogFiltersRequest())
        if isinstance(response, list):
            return response
        filters = getattr(response, "filters", None)
        return list(filters or [])

    def _filter_peer_ids(self, dialog_filter: Any) -> list[int]:
        include = [
            *(getattr(dialog_filter, "pinned_peers", None) or []),
            *(getattr(dialog_filter, "include_peers", None) or []),
        ]
        exclude = {
            int(utils.get_peer_id(peer))
            for peer in (getattr(dialog_filter, "exclude_peers", None) or [])
        }

        result = []
        seen = set()

        for peer in include:
            peer_id = int(utils.get_peer_id(peer))
            if peer_id in exclude or peer_id in seen:
                continue
            seen.add(peer_id)
            result.append(peer_id)

        return result

    def _ensure_authorized(self) -> None:
        if self.auth_state != "authorized":
            raise ValueError("Telegram Reader ещё не авторизован.")

    def _clear_pending_auth(self) -> None:
        self.pending_phone = None
        self.pending_phone_code_hash = None

    async def _handle_rpc(
        self,
        reader: asyncio.StreamReader,
        writer: asyncio.StreamWriter,
    ) -> None:
        try:
            raw = await asyncio.wait_for(reader.readline(), timeout=20)
            if not raw:
                return

            request = json.loads(raw.decode("utf-8"))
            method = request.get("method")
            params = request.get("params") or {}

            if method == "status":
                result = await self.status()
            elif method == "request_code":
                result = await self.request_code(str(params.get("phone", "")))
            elif method == "submit_code":
                result = await self.submit_code(str(params.get("code", "")))
            elif method == "submit_password":
                result = await self.submit_password(str(params.get("password", "")))
            elif method == "folders":
                result = await self.folders()
            elif method == "select_folder":
                result = await self.select_folder(int(params.get("folder_id")))
            elif method == "sync_now":
                result = await self.sync_now()
            elif method == "search":
                result = self.store.search(
                    str(params.get("query", "")),
                    int(params.get("limit", 20)),
                )
            else:
                raise ValueError("Неизвестная команда Reader.")

            payload = {"ok": True, "result": result}
        except Exception as exc:  # noqa: BLE001
            payload = {
                "ok": False,
                "error": str(exc)[:500] or type(exc).__name__,
            }

        writer.write((json.dumps(payload, ensure_ascii=False) + "\n").encode("utf-8"))
        await writer.drain()
        writer.close()
        await writer.wait_closed()


async def main() -> None:
    daemon = ReaderDaemon()
    await daemon.start()

    stop_event = asyncio.Event()
    loop = asyncio.get_running_loop()

    for sig in (signal.SIGTERM, signal.SIGINT):
        try:
            loop.add_signal_handler(sig, stop_event.set)
        except NotImplementedError:
            pass

    await stop_event.wait()
    await daemon.close()


if __name__ == "__main__":
    asyncio.run(main())
