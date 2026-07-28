import unittest
from ..wavefile import WaveFile


class TestWaveFile(unittest.TestCase):
    """Tests for WaveFile class methods"""
    def setUp(self):
        # "bare minimum" valid file contents for a WaveFile
        # (used to test table validation and basic metadata parsing)
        self.minimum_wvm9_table = [
            '%CTF: 1.00\n',
            '%Site: FORT\n',
            '%TimeStamp: 2025 05 13  06 00 00\n',
            '%TimeZone: "UTC" +0.000 0\n',
            '%Origin: 39.3783667  -74.3990167\n',
            '%TableType: WAVL WVM9\n',
            '%TableColumns: 3\n',
            '%TableColumnTypes: COL1 COL2 COL3\n',
            '%TableRows: 4\n',
            ' 1 2 3\n',
            ' 4 5 6\n',
            ' 7 nan 999.00\n',
            ' 8 -999.00 1080.0\n',
            '%TableEnd: \n',
            '%%\n',
            '%%\n',
            '%ProcessedTimeStamp: 2025 05 30  20 00 05\n',
            '%ProcessingTool: "SpectraToWavesModel" 11.10.0\n',
            '%ProcessingTool: "WaveModelArchiver" 14.2.3\n',
        ]

        self.minimum_wm11_table = [
            '%CTF: 1.00\n',
            '%Site: FORT\n',
            '%TimeStamp: 2025 05 13  06 00 00\n',
            '%TimeZone: "UTC" +0.000 0\n',
            '%Origin: 39.3783667  -74.3990167\n',
            '%TableType: WAVL WM11\n',
            '%TableColumns: 3\n',
            '%TableColumnTypes: COL1 COL2 COL3\n',
            ' 1 2 3\n',
            ' 4 5 6\n',
            ' 7 nan 999.00\n',
            ' 8 -999.00 1080.0\n',
            '%TableEnd: \n',
        ]

        # WM12 shares the base column layout with WVM9, so mirror that fixture
        self.minimum_wm12_table = [
            '%CTF: 1.00\n',
            '%Site: FORT\n',
            '%TimeStamp: 2025 05 13  06 00 00\n',
            '%TimeZone: "UTC" +0.000 0\n',
            '%Origin: 39.3783667  -74.3990167\n',
            '%TableType: WAVL WM12\n',
            '%TableColumns: 3\n',
            '%TableColumnTypes: COL1 COL2 COL3\n',
            '%TableRows: 4\n',
            ' 1 2 3\n',
            ' 4 5 6\n',
            ' 7 nan 999.00\n',
            ' 8 -999.00 1080.0\n',
            '%TableEnd: \n',
        ]

        # WM13 adds wind-wave/swell partition columns like WM11, so mirror that fixture
        self.minimum_wm13_table = [
            '%CTF: 1.00\n',
            '%Site: FORT\n',
            '%TimeStamp: 2025 05 13  06 00 00\n',
            '%TimeZone: "UTC" +0.000 0\n',
            '%Origin: 39.3783667  -74.3990167\n',
            '%TableType: WAVL WM13\n',
            '%TableColumns: 3\n',
            '%TableColumnTypes: COL1 COL2 COL3\n',
            ' 1 2 3\n',
            ' 4 5 6\n',
            ' 7 nan 999.00\n',
            ' 8 -999.00 1080.0\n',
            '%TableEnd: \n',
        ]

        # unsupported table type should be rejected
        self.unsupported_table = [
            '%CTF: 1.00\n',
            '%Site: FORT\n',
            '%TimeStamp: 2025 05 13  06 00 00\n',
            '%TimeZone: "UTC" +0.000 0\n',
            '%Origin: 39.3783667  -74.3990167\n',
            '%TableType: WAVL WM14\n',
            '%TableColumns: 3\n',
            '%TableColumnTypes: COL1 COL2 COL3\n',
            ' 1 2 3\n',
            '%TableEnd: \n',
        ]

        # 'bad/garbage data' table used for testing (missing tablecolumns and tablecolumntypes)
        self.bad_data = [
            '%this is\n',
            '%bad data\n',
            '%TableType: WAVL WVM9\n',
            ' 1 2 3\n',
            ' 4 5 6\n',
            ' 7 nan 999.00\n',
            ' 8 -999.00 1080.0\n',
            '%TableEnd: \n',
        ]

        # test WaveFile objects
        self.f1 = WaveFile({
            'filename': 'WVLM_BIGC_2025_05_01_0000.wls',
            'filetype': 'wave',
            'site': 'BIGC',
            'affiliation': 'CODAR',
            'year': '2025',
            'month': '05',
            'timestamp': 1746057600,
        }, self.minimum_wvm9_table, 1746057600)

        self.f2 = WaveFile({
            'filename': 'WVLM_BIGC_2025_05_01_0000.wls',
            'filetype': 'wave',
            'site': 'BIGC',
            'affiliation': 'CODAR',
            'year': '2025',
            'month': '05',
            'timestamp': 1746057600,
        }, self.minimum_wm11_table, 1746057600)

        self.f3 = WaveFile({
            'filename': 'WVLM_BIGC_2025_05_01_0000.wls',
            'filetype': 'wave',
            'site': 'BIGC',
            'affiliation': 'CODAR',
            'year': '2025',
            'month': '05',
            'timestamp': 1746057600,
        }, self.bad_data, 1746057600)

        self.f4 = WaveFile({
            'filename': 'WVLM_BIGC_2025_05_01_0000.wls',
            'filetype': 'wave',
            'site': 'BIGC',
            'affiliation': 'CODAR',
            'year': '2025',
            'month': '05',
            'timestamp': 1746057600,
        }, self.minimum_wm12_table, 1746057600)

        self.f5 = WaveFile({
            'filename': 'WVLM_BIGC_2025_05_01_0000.wls',
            'filetype': 'wave',
            'site': 'BIGC',
            'affiliation': 'CODAR',
            'year': '2025',
            'month': '05',
            'timestamp': 1746057600,
        }, self.minimum_wm13_table, 1746057600)

        self.f6 = WaveFile({
            'filename': 'WVLM_BIGC_2025_05_01_0000.wls',
            'filetype': 'wave',
            'site': 'BIGC',
            'affiliation': 'CODAR',
            'year': '2025',
            'month': '05',
            'timestamp': 1746057600,
        }, self.unsupported_table, 1746057600)

    """file validation tests"""
    def test_valid_wavl_table(self):
        """Test valid_wavl_table method on both valid and invalid table"""
        assert self.f1.valid_wavl_table() == 'WVM9'
        assert self.f2.valid_wavl_table() == 'WM11'
        assert self.f4.valid_wavl_table() == 'WM12'
        assert self.f5.valid_wavl_table() == 'WM13'

        # bad table should return None
        assert self.f3.valid_wavl_table() is None
        # unsupported (but well-formed) table type should return None
        assert self.f6.valid_wavl_table() is None

    """Table/data retrieval tests"""
    def test_get_process_info(self):
        """Test get_process_info method with basic info"""
        p1 = self.f1.get_process_info()
        p2 = self.f2.get_process_info()

        assert p1 == {
            'ProcessedTimeStamp': 1748635205,
            'SpectraToWavesModel': '11.10.0',
            'WaveModelArchiver': '14.2.3',
        }
        # no process data in 'file'
        assert p2 == {}

    def test_get_wave_metadata(self):
        """Test get_wave_metadata method with some basic metadata"""
        m1 = self.f1.get_wave_metadata()
        m2 = self.f2.get_wave_metadata()
        m3 = self.f3.get_wave_metadata()

        assert m1 == {
            'CTF': '1.00',
            'Site': 'FORT',
            'time': 1747116000,
            'TimeZone': '"UTC" +0.000 0',
            'lat': '39.3783667',
            'lon': '-74.3990167',
            'TableType': 'WAVL WVM9',
            'TableColumns': '3',
            'TableColumnTypes': 'COL1 COL2 COL3',
        }
        # no %TableRows defined, so should return none
        assert m2 is None
        # no %CTF defined, so should return none
        assert m3 is None


if __name__ == '__main__':
    unittest.main()