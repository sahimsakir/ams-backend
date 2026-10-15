"""Policy tests and actual weight/inference smoke test; not a PAD accuracy benchmark."""
import unittest
from unittest.mock import patch
import numpy as np
from liveness import PassiveLiveness, require_live

class LivenessTest(unittest.TestCase):
    def test_all_four_frames_must_pass(self):
        self.assertEqual(require_live([(.9, True)] * 4, .8), .9)
        for result in [(.79, True), (.99, False), (float('nan'), True), (1.1, True)]:
            with self.assertRaises(ValueError):
                require_live([(.9, True)] * 3 + [result], .8)

    def test_invalid_configuration_fails_closed(self):
        for threshold in [.1, float('nan'), 1.1]:
            with self.assertRaises(ValueError):
                require_live([(.9, True)] * 4, threshold)
        with self.assertRaises(ValueError):
            require_live([(.9, True)] * 3, .8)

    def test_real_weights_load_and_predict_finite_score(self):
        model = PassiveLiveness()
        score, live = model.score(np.zeros((480, 640, 3), dtype=np.uint8), (140, 400, 340, 200))
        self.assertTrue(np.isfinite(score))
        self.assertTrue(0 <= score <= 1)
        self.assertIsInstance(live, bool)

    def test_missing_or_corrupt_model_is_not_bypassed(self):
        with patch('pathlib.Path.read_bytes', return_value=b'broken'):
            with self.assertRaises(RuntimeError):
                PassiveLiveness()

if __name__ == '__main__':
    unittest.main()
