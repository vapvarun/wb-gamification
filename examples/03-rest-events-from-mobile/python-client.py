"""
WB Gamification — REST events ingestion via Python.

Production-ready client class with proper auth, timeout handling,
and retry logic. Works with the standard `requests` library.

    pip install requests

Usage:
    from python_client import WbGamClient
    c = WbGamClient(site="https://your-site.com", api_key="wbgam_...")
    c.ingest("yourplugin_lesson_complete", user_id=42,
             metadata={"lesson_id": "js-101"})
"""

from __future__ import annotations
import os
from dataclasses import dataclass
from typing import Any
import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry


@dataclass
class WbGamClient:
    """Client for the WB Gamification REST API."""

    site: str
    api_key: str | None = None
    user_login: str | None = None    # Application Password: user
    app_password: str | None = None  # Application Password: token
    timeout_seconds: float = 5.0
    max_retries: int = 3

    def __post_init__(self) -> None:
        self.session = requests.Session()
        retry = Retry(
            total=self.max_retries,
            backoff_factor=0.5,
            status_forcelist=[429, 500, 502, 503, 504],
            allowed_methods={"GET", "POST"},
        )
        self.session.mount("https://", HTTPAdapter(max_retries=retry))
        self.session.mount("http://", HTTPAdapter(max_retries=retry))

    def _auth_headers(self) -> dict[str, str]:
        """Pick the right auth based on what was passed to the constructor."""
        if self.api_key:
            return {"X-WB-Gam-Key": self.api_key}
        return {}

    def _auth_basic(self) -> tuple[str, str] | None:
        """HTTP Basic auth tuple for Application Password mode, if configured."""
        if self.user_login and self.app_password:
            return (self.user_login, self.app_password)
        return None

    def ingest(
        self,
        action_id: str,
        *,
        user_id: int | None = None,
        object_id: int | None = None,
        metadata: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        """Fire a gamification event.

        action_id must be a registered action — use `actions()` to list.
        """
        body: dict[str, Any] = {"action_id": action_id}
        if user_id is not None:
            body["user_id"] = user_id
        if object_id is not None:
            body["object_id"] = object_id
        if metadata:
            body["metadata"] = metadata

        res = self.session.post(
            f"{self.site}/wp-json/wb-gamification/v1/events",
            json=body,
            headers=self._auth_headers(),
            auth=self._auth_basic(),
            timeout=self.timeout_seconds,
        )
        res.raise_for_status()
        return res.json()

    def member_points(self, user_id: int) -> dict[str, Any]:
        res = self.session.get(
            f"{self.site}/wp-json/wb-gamification/v1/members/{user_id}/points",
            headers=self._auth_headers(),
            auth=self._auth_basic(),
            timeout=self.timeout_seconds,
        )
        res.raise_for_status()
        return res.json()

    def actions(self) -> list[dict[str, Any]]:
        """List every registered action (public endpoint, no auth)."""
        res = self.session.get(
            f"{self.site}/wp-json/wb-gamification/v1/actions",
            timeout=self.timeout_seconds,
        )
        res.raise_for_status()
        return res.json()

    def leaderboard(
        self,
        period: str = "all_time",
        limit: int = 10,
    ) -> dict[str, Any]:
        """Fetch the leaderboard (public endpoint, no auth)."""
        res = self.session.get(
            f"{self.site}/wp-json/wb-gamification/v1/leaderboard",
            params={"period": period, "limit": limit},
            timeout=self.timeout_seconds,
        )
        res.raise_for_status()
        return res.json()


if __name__ == "__main__":
    client = WbGamClient(
        site=os.environ.get("SITE", "http://wb-gamification.local"),
        api_key=os.environ.get("WBGAM_API_KEY"),
    )

    actions = client.actions()
    print(f"Registered actions: {len(actions)}")

    result = client.ingest(
        "wp_post_receives_comment",
        user_id=1,
        metadata={"source": "python_example"},
    )
    print(f"event_id: {result['event_id']}")

    points = client.member_points(1)
    print(f"Total points: {points['total']}")

    leaderboard = client.leaderboard(period="all_time", limit=5)
    print(f"Top 5: {[r['display_name'] for r in leaderboard['rows']]}")
