"""Synthetic regression fixtures; no external services or real credentials."""
import logging
import sys
from pathlib import Path
from unittest.mock import patch

import pytest
import requests
from fastapi import Request

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "backend"))
from services.error_handler import log_error
from services.sk_ruz_provider import RuzProvider
from services.sk_zrsr_provider import ZrsrProvider


def test_error_log_omits_credentials_and_personal_data(caplog):
    marker = "synthetic-sensitive-value"
    request = Request({"type": "http", "method": "GET", "scheme": "https",
                       "server": ("example.invalid", 443), "path": "/" + marker,
                       "query_string": ("token=" + marker).encode(),
                       "headers": [(b"authorization", ("Bearer " + marker).encode()),
                                   (b"cookie", marker.encode()),
                                   (b"x-api-key", marker.encode())]})
    with caplog.at_level(logging.ERROR, logger="iluminati"):
        try:
            raise ValueError(marker)
        except ValueError as exc:
            log_error(exc, {"nested": {"password": marker}}, request)
    assert marker not in caplog.text
    assert "ValueError" in caplog.text
    assert "GET" in caplog.text


class CertificateFailure(requests.adapters.BaseAdapter):
    def send(self, request, **kwargs):
        assert kwargs["verify"] is True
        raise requests.exceptions.SSLError("synthetic certificate rejection")

    def close(self):
        pass


@pytest.mark.parametrize("factory", [RuzProvider, ZrsrProvider])
def test_provider_rejects_invalid_certificate_without_insecure_retry(factory):
    provider = factory()
    provider.session.trust_env = False
    provider.session.mount("https://", CertificateFailure())
    with patch("time.sleep"):
        assert provider._make_request_with_retry("https://example.invalid/") is None
    assert provider.session.verify is True
