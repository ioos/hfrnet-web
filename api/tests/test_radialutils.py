import unittest
import configparser
import pandas as pd

from utils import (get_regions, find_closest, bounding_boxes_intersect,
                   last_day_of_month, df_to_geojson, read_waves_row,
                   float_or_none)
from datetime import datetime, timezone
import geopandas as gpd
from shapely.geometry import Point

class TestRadialUtils(unittest.TestCase):
    """Test util functions needed for radialdata API endpoints"""

    def setUp(self):
        """Mock config to set up tests requiring the config params"""

        test_config = configparser.ConfigParser()
        test_config.read_dict({
            'urls': {
                'valid_res': '1km,2km,6km,500m',
                'valid_pfx': 'h,a,am,ay',
                'default_prod': 'a_6km',
                'default_lat1': '73',
                'default_lat2': '13',
                'default_lon1': '-170',
                'default_lon2': '-49',
                'default_time_offset': '54000',
                'default_out': 'json',
                'default_callback': 'eqfeed_callback'
            },
            'usregions': {
                'AKNS': '68, -174, 74, -128',
                'GAK': '50, -167, 62, -123',
                'GLNA': '41, -92, 50, -76',
                'USWC': '30, -130, 50, -115',
                'USEGC': '21, -97, 47, -57',
                'USHI': '16, -163, 25, -151',
                'PRVI': '14, -70, 22, -61'
            },
            'waves': {'default_history_t0': '518400'}
        })

        self.test_config = test_config

        # small test dataframe containing 3 rows of just what is needed to test our core fns with
        d = {'head': pd.Series([30, 60, 90]),
             'magni': pd.Series([1, 2, 3]),
             'u': pd.Series([0.1, 0.2, 0.3]),
             'v': pd.Series([-0.1, -0.2, -0.3])}
        points = gpd.points_from_xy(pd.Series([-155, -154, -153]),
                                    pd.Series([19, 20, 21]))

        self.test_df = gpd.GeoDataFrame(d, geometry=points,
                                        crs="EPSG:4326")



    def test_last_day_of_month(self):
        """Test that last day of month returns correct result for different month values"""

        q = last_day_of_month(datetime(2024, 10, 4, 3, 0, tzinfo=timezone.utc).timestamp())
        # check 31 day month
        assert q == datetime(2024, 10, 31, 3, 0, tzinfo=timezone.utc)

        q = last_day_of_month(datetime(2024, 11, 10, 5, 0, tzinfo=timezone.utc).timestamp())
        # check 30 day month
        assert q == datetime(2024, 11, 30, 5, 0, tzinfo=timezone.utc)

        q = last_day_of_month(datetime(2024, 2, 12, 15, 0, tzinfo=timezone.utc).timestamp())
        # check leap year
        assert q == datetime(2024, 2, 29, 15, 0, tzinfo=timezone.utc)


    def test_get_regions(self):
        """Tests get_regions and get_us_regions with various bounding boxes"""

        q = get_regions(73, -170, 13, -49)
        # test config default bbox to make sure all test_config regions are returned
        assert set(q) == {'AKNS', 'GAK', 'GLNA', 'USWC', 'USEGC', 'USHI', 'PRVI'}

        q = get_regions(70, -170, 60, -160)
        # should return AKNS and GAK
        assert set(q) == {'AKNS', 'GAK'}

        q = get_regions(70, -50, 20, -30)
        # should return no regions, but lat of input bbox contains lats of all regions
        assert set(q) == set({})

        q = get_regions(10, -170, 0, -49)
        # should return no regions, but lon of input bbox contains lons of all regions
        assert set(q) == set({})


    def test_bounding_boxes_intersect(self):
        """Tests bounding_boxes_intersect with various bounding boxes"""

        # get_regions above uses this heavily so we can just try a few edge cases here

        q = bounding_boxes_intersect([10, -30, 20, 30], [20, -35, 15, 10])
        # test flipping of lats feature (when bbox goes from bottom-top instead of top-bottom)
        assert q == True

        q = bounding_boxes_intersect([10, 170, 40, -170], [20, -175, 50, -150])
        # test lon date line cross
        assert q == True

        q = bounding_boxes_intersect([10, 170, 20, -170], [25, 160, 70, -170])
        # test lon date line cross but lats do not match
        assert q == False


    def test_df_to_geojson(self):
        """Tests dataframe to geojson using the simple test dataframe"""

        q = df_to_geojson(self.test_df, ['head', 'magni', 'u', 'v'], time='1596510000', product='a_6km')
        assert q == {'type': 'FeatureCollection', 'product': 'a_6km', 'time': '1596510000', 'features':
            [{'type': 'Feature', 'properties': {'head': 30, 'magni': 1, 'u': 0.1, 'v': -0.1},
              'geometry': {'type': 'Point', 'coordinates': (-155.0, 19.0)}},
             {'type': 'Feature', 'properties': {'head': 60, 'magni': 2, 'u': 0.2, 'v': -0.2},
              'geometry': {'type': 'Point', 'coordinates': (-154.0, 20)}},
             {'type': 'Feature', 'properties': {'head': 90, 'magni': 3, 'u': 0.3, 'v': -0.3},
              'geometry': {'type': 'Point', 'coordinates': (-153.0, 21.0)}}]}


    @staticmethod
    def _waves_row(**overrides):
        """Builds a full waves DB row (as returned by the /waves query), with overrides"""
        row = {
            'sta': 'BIGC',
            'net': 'CeNCOOS',
            'time': 1785247200.0,
            'lat': 36.7,
            'lon': -122.7,
            'LATD': 36.7070242,
            'LOND': -122.7142876,
            'MWHT': 1.96,
            'MWPD': 8.0,
            'WAVB': 317.0,
            'WNDB': 312.0,
        }
        row.update(overrides)
        return row


    def test_read_waves_row(self):
        """Tests read_waves_row value passthrough and coordinate preference"""

        q = read_waves_row(self._waves_row())
        # data-row coordinates (LATD/LOND) preferred over header lat/lon
        assert q == {
            'lat': 36.7070242,
            'lon': -122.7142876,
            'time': 1785247200.0,
            'sta': 'BIGC',
            'net': 'CeNCOOS',
            'MWHT': 1.96,
            'MWPD': 8.0,
            'WAVB': 317.0,
            'WNDB': 312.0,
        }

        q = read_waves_row(self._waves_row(LATD=0, LOND=0))
        # zero LATD/LOND means missing; fall back to header lat/lon
        assert q['lat'] == 36.7 and q['lon'] == -122.7

        q = read_waves_row(self._waves_row(LATD=None, LOND=None))
        # NULL LATD/LOND also falls back to header lat/lon
        assert q['lat'] == 36.7 and q['lon'] == -122.7


    def test_read_waves_row_null_values(self):
        """Tests that NULL wave values pass through instead of dropping the row / zero-filling"""

        q = read_waves_row(self._waves_row(MWHT=None, MWPD=None, WAVB=None,
                                           WNDB=None, time=None))
        # station placeholder rows (no data sentence near the selected time)
        # are kept so the client can render a grey NaN marker
        assert q is not None
        assert q['MWHT'] is None
        assert q['MWPD'] is None
        assert q['WAVB'] is None
        assert q['WNDB'] is None
        assert q['time'] is None
        assert q['lat'] == 36.7070242 and q['lon'] == -122.7142876

        q = read_waves_row(self._waves_row(MWPD=None, WNDB=None))
        # partial NULLs pass through as None (not fabricated as 0)
        assert q['MWHT'] == 1.96 and q['MWPD'] is None
        assert q['WAVB'] == 317.0 and q['WNDB'] is None

        q = read_waves_row(self._waves_row(lat=None, lon=None, LATD=None, LOND=None))
        # rows without any usable coordinates cannot be placed on the map
        assert q is None


    def test_float_or_none(self):
        """Tests float conversion helper used for waves feature serialization"""

        assert float_or_none(None) is None
        assert float_or_none('1.5') == 1.5
        assert float_or_none(2) == 2.0


    def test_find_closest(self):
        """Tests find_closest function using the simple test dataframe"""

        expected_out = gpd.GeoDataFrame([{
            'head': 90.0,
            'magni': 3.0,
            'u': 0.3,
            'v': -0.3}],
            geometry=[Point(-154, 22.0)],
            crs="EPSG:4326").iloc[0]
        q = find_closest(self.test_df, 22.0, -154.0)
        assert (q.sort_index() == expected_out.sort_index()).all()


if __name__ == '__main__':
    unittest.main()
