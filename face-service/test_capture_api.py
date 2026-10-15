"""API contract tests with controlled face/PAD outputs, not accuracy tests."""
import importlib
import io
import os
import sys
import types
import unittest
from unittest.mock import patch
import numpy as np
from PIL import Image
from fastapi.testclient import TestClient

class CaptureApiTest(unittest.TestCase):
    def setUp(self):
        fake_face = types.SimpleNamespace(face_locations=lambda *_a, **_k: [(80, 260, 260, 80)], face_encodings=lambda *_a, **_k: [np.zeros(128)])
        with patch.dict(sys.modules, {'face_recognition': fake_face}):
            self.module = importlib.import_module('app')
        self.module.face_recognition = fake_face
        self.module.pad = types.SimpleNamespace(score=lambda *_a: (.95, True))
        self.client = TestClient(self.module.app)
        self.env = patch.dict(os.environ, {'FACE_SERVICE_KEY': 'test-key'})
        self.env.start()
        self.addCleanup(self.env.stop)

    def request(self, duplicate=False, key='test-key'):
        frames = []
        for i in range(4):
            image = Image.new('RGB', (400, 400), (100 if duplicate else 100+i, 80, 70))
            out = io.BytesIO()
            image.save(out, format='JPEG')
            frames.append(('frames', (f'{i}.jpg', out.getvalue(), 'image/jpeg')))
        import json
        return self.client.post('/verify-capture', files=frames, data={'known': json.dumps([np.zeros(128).tolist()]), 'live_threshold': '.8'}, headers={'X-Service-Key': key})

    def test_pass_returns_explicit_bound_contract(self):
        response = self.request()
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()['frames_checked'], 4)
        self.assertTrue(response.json()['live'])

    def test_spoof_and_duplicate_frames_are_rejected(self):
        self.module.pad.score = lambda *_a: (.01, False)
        self.assertEqual(self.request().status_code, 422)
        self.module.pad.score = lambda *_a: (.95, True)
        self.assertEqual(self.request(duplicate=True).status_code, 422)

    def test_service_unavailable_and_wrong_key_fail_closed(self):
        self.module.pad = None
        self.assertEqual(self.request().status_code, 503)
        self.assertEqual(self.request(key='bad').status_code, 401)

    def test_any_bad_frame_rejects_whole_capture(self):
        self.module.pad.score = unittest.mock.Mock(side_effect=[(.95, True)]*3+[(.2, False)])
        self.assertEqual(self.request().status_code, 422)
