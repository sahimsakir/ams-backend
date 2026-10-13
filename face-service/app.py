import io
import os
import secrets
import warnings
import numpy as np
import face_recognition
from PIL import Image
from fastapi import FastAPI, File, UploadFile, Header, HTTPException

app = FastAPI(docs_url=None, redoc_url=None)
Image.MAX_IMAGE_PIXELS = 12_000_000
warnings.simplefilter("error", Image.DecompressionBombWarning)

@app.get("/health")
def health():
    return {"status": "ok"}

@app.post("/encode")
def encode(photo: UploadFile = File(...), x_service_key: str = Header(default="")):
    key = os.environ.get("FACE_SERVICE_KEY", "")
    if not key or not secrets.compare_digest(key, x_service_key):
        raise HTTPException(401, "Unauthorized")
    raw = photo.file.read(4 * 1024 * 1024 + 1)
    if len(raw) > 4 * 1024 * 1024:
        raise HTTPException(413, "Image too large")
    try:
        image = Image.open(io.BytesIO(raw))
        if image.format not in ("JPEG", "PNG"):
            raise ValueError("Unsupported image")
        image = image.convert("RGB")
        image.thumbnail((1000, 1000))
        pixels = np.array(image)
        locations = face_recognition.face_locations(pixels, model="hog")
        if len(locations) != 1:
            raise ValueError("Exactly one face required")
        top, right, bottom, left = locations[0]
        if min(bottom - top, right - left) < 80:
            raise ValueError("Move closer to the camera")
        embedding = face_recognition.face_encodings(pixels, locations, num_jitters=1)[0]
        return {"embedding": embedding.tolist()}
    except Exception:
        raise HTTPException(422, "Use one clear, well-lit face")
