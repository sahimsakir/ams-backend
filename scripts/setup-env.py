from pathlib import Path
import base64, secrets
root = Path(__file__).resolve().parents[1]
path = root / ".env"
if path.exists():
    raise SystemExit(".env exists; refusing to overwrite secrets")
text = (root / ".env.example").read_text()
values = {"APP_KEY": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(), "DB_PASSWORD": secrets.token_urlsafe(32), "FACE_SERVICE_KEY": secrets.token_urlsafe(32), "OWNER_PASSWORD": secrets.token_urlsafe(18)}
for key, value in values.items():
    text = text.replace(key + "=\n", key + "=" + value + "\n")
path.write_text(text)
path.chmod(0o600)
print("Created .env. Read OWNER_EMAIL and OWNER_PASSWORD there to sign in.")
