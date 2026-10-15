"""CPU-only, cached MiniFASNet ensemble. Scores are not calibrated probabilities."""
import hashlib
import json
import os
from pathlib import Path
import cv2
import numpy as np
import torch
from minivision.models import MiniFASNetV2, MiniFASNetV1SE
from minivision.crop import CropImage

MODEL_VERSION = 'minivision-b6d5f04-ensemble-v1'

class PassiveLiveness:
    def __init__(self):
        torch.set_num_threads(max(1, int(os.environ.get('FACE_CPU_THREADS', '2'))))
        root = Path(__file__).parent / 'minivision'
        hashes = json.loads((root / 'weights.json').read_text())
        self.models = []
        for name, factory, scale in [('2.7_80x80_MiniFASNetV2.pth', MiniFASNetV2, 2.7), ('4_0_0_80x80_MiniFASNetV1SE.pth', MiniFASNetV1SE, 4.0)]:
            path = root / name
            if hashlib.sha256(path.read_bytes()).hexdigest() != hashes[name]:
                raise RuntimeError('Invalid liveness weights')
            state = torch.load(path, map_location='cpu', weights_only=True)
            state = {k.removeprefix('module.'): v for k, v in state.items()}
            model = factory(conv6_kernel=(5, 5))
            model.load_state_dict(state, strict=True)
            model.eval()
            self.models.append((model, scale))
        self.cropper = CropImage()

    def score(self, rgb, box):
        top, right, bottom, left = box
        bgr = cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR)
        bbox = [left, top, right-left+1, bottom-top+1]
        probabilities = []
        with torch.inference_mode():
            for model, scale in self.models:
                patch = self.cropper.crop(bgr, bbox, scale, 80, 80)
                # Upstream's ndarray ToTensor intentionally does NOT divide by 255.
                tensor = torch.from_numpy(np.ascontiguousarray(patch.transpose(2, 0, 1))).float().unsqueeze(0)
                probabilities.append(torch.softmax(model(tensor), dim=1)[0].numpy())
        combined = np.mean(probabilities, axis=0)
        if combined.shape != (3,) or not np.isfinite(combined).all():
            raise RuntimeError('Invalid liveness prediction')
        return float(combined[1]), int(np.argmax(combined)) == 1


def require_live(results, threshold):
    if len(results) != 4 or not np.isfinite(threshold) or not 0.5 <= threshold <= 1:
        raise ValueError('Invalid liveness configuration or frame count')
    # Every frame must pass; no majority vote that could hide an attack frame.
    if any(not is_live or not np.isfinite(score) or score < threshold or score > 1 for score, is_live in results):
        raise ValueError('Liveness rejected')
    return min(score for score, _ in results)
