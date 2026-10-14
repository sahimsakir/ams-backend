import os
import tempfile
import unittest
from unittest.mock import patch
import numpy as np
import cv2
from passive import prepare_face, real_probability, model, MEAN, SCALE

class PassiveTest(unittest.TestCase):
    def test_original_rgb_normalization_and_tensor_shape(self):
        rgb = np.full((180,180,3),[100,150,200],dtype=np.uint8)
        value = prepare_face(rgb,(10,170,170,10))
        self.assertEqual(value.shape,(1,3,128,128))
        self.assertEqual(value.dtype,np.float32)
        np.testing.assert_allclose(value[0,:,0,0],(np.array([100,150,200])-MEAN)/SCALE,rtol=1e-6)
    def test_tensor_matches_reference_cubic_resize_and_rgb_normalization(self):
        rgb=np.random.default_rng(42).integers(0,256,(160,160,3),dtype=np.uint8)
        resized=cv2.resize(rgb[10:140,20:150],(128,128),interpolation=cv2.INTER_CUBIC)
        mean=np.array([.5931,.4690,.4229]).reshape(3,1,1)
        std=np.array([.2471,.2214,.2157]).reshape(3,1,1)
        reference=((resized.transpose(2,0,1).astype(np.float32)/255-mean)/std).astype(np.float32)[None]
        np.testing.assert_allclose(prepare_face(rgb,(10,150,140,20)),reference,rtol=1e-5,atol=5e-7)
        bilinear=cv2.resize(rgb[10:140,20:150],(128,128),interpolation=cv2.INTER_LINEAR)
        self.assertFalse(np.array_equal(resized,bilinear))

    def test_empty_crop_is_rejected(self):
        with self.assertRaises(ValueError): prepare_face(np.zeros((10,10,3),dtype=np.uint8),(3,2,3,2))
    def test_real_class_is_zero_not_spoof_class(self):
        self.assertAlmostEqual(real_probability([[.98,.02]]),.98)
        self.assertAlmostEqual(real_probability([[.01,.99]]),.01)
    def test_invalid_probabilities_fail_closed(self):
        for output in ([[float('nan'),1]],[[float('inf'),0]],[[2,-1]],[[.3,.3]],[[.5]],[[.5,.5,.5]]):
            with self.subTest(output=output):
                with self.assertRaises(RuntimeError): real_probability(output)
    def test_missing_and_changed_weights_fail_closed(self):
        with tempfile.TemporaryDirectory() as root:
            path=os.path.join(root,'missing.onnx')
            with patch.dict(os.environ,{'PAD_MODEL_PATH':path}):
                model.cache_clear()
                with self.assertRaises(FileNotFoundError): model()
                with open(path,'wb') as f: f.write(b'not model weights')
                with self.assertRaises(RuntimeError): model()
        model.cache_clear()

if __name__ == '__main__': unittest.main()
