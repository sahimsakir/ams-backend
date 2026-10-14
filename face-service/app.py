import io
import json
import hashlib
import os
import secrets
import warnings
import numpy as np
import face_recognition
from PIL import Image
from fastapi import FastAPI, File, Form, UploadFile, Header, HTTPException
from motion import metrics, verify_motion

app = FastAPI(docs_url=None, redoc_url=None)
Image.MAX_IMAGE_PIXELS = 12_000_000
warnings.simplefilter("error", Image.DecompressionBombWarning)

@app.get("/health")
def health():
    return {"status": "ok"}

# This is an active challenge, NOT certified presentation-attack detection.
# It rejects unchanging images and checks pose/eye motion; replay/deepfake attacks
# require independently evaluated PAD or supervised verification.
@app.post('/verify-step')
def verify_step(frames: list[UploadFile] = File(...), pose: str = Form(...),
                baseline: str = Form('null'), known: str = Form('[]'), threshold: float = Form(.45),
                x_service_key: str = Header(default='')):
    key = os.environ.get('FACE_SERVICE_KEY', '')
    if not key or not secrets.compare_digest(key, x_service_key):
        raise HTTPException(401, 'Unauthorized')
    if not 4 <= len(frames) <= 16 or pose not in ('front','left','right','up','down','blink'):
        raise HTTPException(422, 'Invalid step')
    try:
        reference = np.asarray(json.loads(known), dtype=float)
        base = json.loads(baseline)
        vectors, samples, distances, raw_hashes = [], [], [], set()
        for frame in frames:
            raw = frame.file.read(512*1024+1)
            digest = hashlib.sha256(raw).digest()
            if len(raw) > 512*1024 or digest in raw_hashes: raise ValueError('Frozen frames')
            raw_hashes.add(digest)
            image = Image.open(io.BytesIO(raw))
            if image.format != 'JPEG': raise ValueError('JPEG required')
            image = image.convert('RGB')
            image.thumbnail((640,640))
            pixels = np.asarray(image)
            boxes = face_recognition.face_locations(pixels, model='hog')
            if len(boxes) != 1: raise ValueError('One face required in every frame')
            top,right,bottom,left = boxes[0]
            if min(bottom-top, right-left) < 80: raise ValueError('Move closer')
            vector = face_recognition.face_encodings(pixels, boxes)[0]
            if not reference.size: reference = np.asarray([vector])
            distance = float(np.min(np.linalg.norm(reference-vector, axis=1)))
            if not np.isfinite(distance) or distance > min(threshold, .5): raise ValueError('Face mismatch')
            distances.append(distance)
            vectors.append(vector)
            samples.append(metrics(face_recognition.face_landmarks(pixels, boxes, model='large')[0]))
        base = verify_motion(pose, samples, base)
        return {'embedding': vectors[len(vectors)//2].tolist(), 'baseline': base, 'distance': max(distances)}
    except Exception:
        raise HTTPException(422, 'Live camera step failed. Follow the prompt with one clear face.')


@app.post('/verify-capture')
def verify_capture(photo: UploadFile = File(...), known: str = Form(...), threshold: float = Form(.45), x_service_key: str = Header(default='')):
    key = os.environ.get('FACE_SERVICE_KEY', '')
    if not key or not secrets.compare_digest(key, x_service_key):
        raise HTTPException(401, 'Unauthorized')
    try:
        raw = photo.file.read(1024*1024+1)
        if len(raw) > 1024*1024: raise ValueError('Capture too large')
        image = Image.open(io.BytesIO(raw))
        if image.format != 'JPEG': raise ValueError('JPEG required')
        image = image.convert('RGB')
        image.thumbnail((640,640))
        pixels = np.asarray(image)
        boxes = face_recognition.face_locations(pixels, model='hog')
        if len(boxes) != 1: raise ValueError('Exactly one face required')
        top,right,bottom,left = boxes[0]
        if min(bottom-top,right-left) < 80: raise ValueError('Move closer')
        vector = face_recognition.face_encodings(pixels, boxes)[0]
        reference = np.asarray(json.loads(known), dtype=float)
        if reference.ndim != 2 or reference.shape[1] != 128 or not reference.size or not np.isfinite(reference).all():
            raise ValueError('Invalid enrolled template')
        distance = float(np.min(np.linalg.norm(reference-vector, axis=1)))
        if not np.isfinite(distance) or distance > min(threshold,.5):
            raise ValueError('Face mismatch')
    except Exception:
        raise HTTPException(422, 'Face not verified. Use one clear face matching your enrolled profile.')
    baseline = metrics(face_recognition.face_landmarks(pixels, boxes, model='large')[0])
    return {'distance': distance, 'baseline': baseline}
