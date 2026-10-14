"""Passive RGB anti-spoof classification, not a proof of physical camera origin."""
import hashlib
import os
from functools import lru_cache
from pathlib import Path
import numpy as np
import cv2
from download_pad import MODEL_SHA384

MEAN = np.array([151.2405, 119.5950, 107.8395], dtype=np.float32)
SCALE = np.array([63.0105, 56.4570, 55.0035], dtype=np.float32)

def prepare_face(rgb, box):
    top, right, bottom, left = box
    height, width = rgb.shape[:2]
    crop = rgb[max(0,top):min(height,bottom), max(0,left):min(width,right)]
    if not crop.size: raise ValueError('Invalid face crop')
    # Match the original Torch/ONNX pipeline, whose texture classifier uses cubic resizing.
    pixels = cv2.resize(crop, (128,128), interpolation=cv2.INTER_CUBIC).astype(np.float32)
    return np.ascontiguousarray(((pixels-MEAN)/SCALE).transpose(2,0,1)[None])

def real_probability(output):
    values = np.asarray(output, dtype=np.float64).reshape(-1)
    if values.shape != (2,) or not np.isfinite(values).all(): raise RuntimeError('Invalid anti-spoof response')
    # The official original ONNX returns probabilities, class 0 real, class 1 spoof.
    if (values < 0).any() or (values > 1).any() or not np.isclose(values.sum(), 1, atol=1e-4):
        raise RuntimeError('Invalid anti-spoof probabilities')
    return float(values[0])

@lru_cache(maxsize=1)
def model():
    import onnxruntime as ort
    path = Path(os.environ.get('PAD_MODEL_PATH', 'models/anti-spoof-mn3.onnx'))
    if hashlib.sha384(path.read_bytes()).hexdigest() != MODEL_SHA384:
        raise RuntimeError('Anti-spoof weights missing or changed')
    options = ort.SessionOptions()
    options.intra_op_num_threads = 2
    options.inter_op_num_threads = 1
    session = ort.InferenceSession(str(path), options, providers=['CPUExecutionProvider'])
    if session.get_inputs()[0].shape != [1,3,128,128]: raise RuntimeError('Unexpected anti-spoof input')
    return session

def live_score(rgb, box):
    session = model()
    return real_probability(session.run(None, {session.get_inputs()[0].name: prepare_face(rgb,box)})[0])
