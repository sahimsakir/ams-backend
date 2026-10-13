import unittest
from motion import verify_motion, eye_ratio

class MotionTest(unittest.TestCase):
    def setUp(self):
        self.base = {'yaw':0., 'pitch':.4, 'ear':.3}
    def sample(self, **kw):
        return {**self.base, **kw}
    def test_front_requires_open_eyes_and_centered_face(self):
        self.assertEqual(verify_motion('front',[self.base]*4,None), self.base)
        for changed in ({'ear':.1},{'yaw':.4},{'pitch':.8}):
            with self.assertRaises(ValueError): verify_motion('front',[self.sample(**changed)]*4,None)
    def test_each_direction_requires_its_own_motion(self):
        for pose, values in [('left',{'yaw':.2}),('right',{'yaw':-.2}),('up',{'pitch':.28}),('down',{'pitch':.52})]:
            with self.subTest(pose=pose):
                verify_motion(pose,[self.sample(**values)]*4,self.base)
                with self.assertRaises(ValueError): verify_motion(pose,[self.base]*4,self.base)
    def test_wrong_direction_is_rejected(self):
        with self.assertRaises(ValueError): verify_motion('left',[self.sample(yaw=-.2)]*4,self.base)
    def test_blink_requires_open_closed_open_order(self):
        open_eye=self.base; closed=self.sample(ear=.14)
        verify_motion('blink',[open_eye,open_eye,closed,closed,open_eye],self.base)
        for seq in ([open_eye]*8,[closed]*8,[closed,open_eye,open_eye],[open_eye,closed,closed]):
            with self.assertRaises(ValueError): verify_motion('blink',seq,self.base)
    def test_head_turn_does_not_count_as_blink(self):
        with self.assertRaises(ValueError): verify_motion('blink',[self.base,self.sample(ear=.1,yaw=.4),self.base],self.base)
    def test_final_front_requires_return_to_baseline(self):
        verify_motion('front',[self.base]*4,self.base)
        with self.assertRaises(ValueError): verify_motion('front',[self.sample(pitch=.6)]*4,self.base)
    def test_eye_ratio_is_scale_independent(self):
        eye=[(0,0),(2,-2),(6,-2),(8,0),(6,2),(2,2)]
        self.assertEqual(eye_ratio(eye),eye_ratio([(x*2,y*2) for x,y in eye]))

if __name__ == '__main__': unittest.main()
