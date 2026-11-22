"""Run: python3 -m unittest discover -s docker/analysis/tests -t docker/analysis (pure Python, no numpy)."""
import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import curve  # noqa: E402


class CurveTest(unittest.TestCase):
    def test_centers_run_from_half_a_patch_to_half_a_patch_before_the_end(self):
        c = curve.centers(5, 20.0, 4.0)
        self.assertEqual(c[0], 2.0)
        self.assertEqual(c[-1], 18.0)
        self.assertEqual(len(c), 5)

    def test_one_patch_sits_in_the_middle_and_a_short_track_does_not_go_negative(self):
        self.assertEqual(curve.centers(1, 10.0, 3.0), [5.0])
        c = curve.centers(3, 2.0, 3.0)
        self.assertTrue(all(0 <= t <= 2.0 for t in c))

    def test_window_means_follow_a_rising_curve(self):
        rows = [[i / 19.0, 1 - i / 19.0] for i in range(20)]   # 20 patches over 30 s
        out = curve.window_means(rows, 30.0, 3.0, 3.0)
        self.assertEqual(len(out), 10)
        self.assertLess(out[0][0], out[-1][0])
        self.assertGreater(out[0][1], out[-1][1])

    def test_a_window_without_a_patch_uses_the_nearest_one(self):
        out = curve.window_means([[0.2], [0.8]], 30.0, 3.0, 3.0)
        self.assertEqual(len(out), 10)
        self.assertEqual(out[0][0], 0.2)
        self.assertEqual(out[9][0], 0.8)

    def test_range_means_average_the_patches_inside_each_range(self):
        rows = [[0.0, 0.0]] * 10 + [[1.0, 2.0]] * 10   # first half quiet, second half loud
        out = curve.range_means(rows, 40.0, 2.0, [(0, 18), (22, 40)])
        self.assertEqual(out[0], [0.0, 0.0])
        self.assertEqual(out[1], [1.0, 2.0])

    def test_empty_input(self):
        self.assertEqual(curve.window_means([], 10.0, 3.0, 3.0), [])
        self.assertEqual(curve.range_means([], 10.0, 3.0, [(0, 5)]), [])


if __name__ == "__main__":
    unittest.main()
