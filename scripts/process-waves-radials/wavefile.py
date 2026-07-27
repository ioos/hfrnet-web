from basefile import BaseFile
import re
import logging
import datetime

logger = logging.getLogger(__name__)

class WaveFile(BaseFile):

    # WAVL table types this processor knows how to parse. WVM9/WVMT/WM12 share the
    # base column layout; WM11/WM13 add wind-wave/swell partition columns. All are
    # parsed identically since column mapping is driven by %TableColumnTypes.
    SUPPORTED_TABLE_TYPES = ('WVM9', 'WM11', 'WVMT', 'WM12', 'WM13')

    def __init__(self, file_info, file_data, timestamp):
        """The constructor for the WaveFile class.

        :param file_info: all info extracted from the file name
        :param file_data: all data extracted from the file
        :param timestamp: last modified timestamp of the file (from s3)
        """
        super().__init__(file_info, file_data)
        self.lastmodified = timestamp
        # set to actual table type upon validation
        self.table_type = ''

    def valid_wavl_table(self):
        """Checks for a valid, supported "WAVL" wave table in the file.

        :param file_data: file data as list of strings, where each element is a line from the file
        :return: one of WaveFile.SUPPORTED_TABLE_TYPES (e.g. "WVM9", "WM11", "WM12") | None
        """
        wavl_pattern = re.compile(r'^\s*%TableType:\s+WAVL\s+(\w+)')
        table_type = None
        found = None
        for line in self.file_data:
            match = wavl_pattern.search(line)
            if match is not None:
                found = match.group(1).strip()
                if found in self.SUPPORTED_TABLE_TYPES:
                    table_type = found
                break
        if table_type is None:
            if found:
                logger.error(f"Unsupported table type 'WAVL {found}'")
            else:
                logger.error("No WAVL %TableType found in file")
            return None

        pattern = re.compile(r'^\s*[^%]')
        result_line = None
        for line in self.file_data:
            match = pattern.search(line)
            if match is not None:
                result_line = line
                break
        if result_line is None:
            logger.error("No wave data found")
            return None

        # get waves table (between "%TableType" and "%TableEnd"
        start_ind = None
        end_ind = None
        p1 = re.compile(r'%TableType')
        p2 = re.compile(r'%TableEnd')
        for i, line in enumerate(self.file_data):
            if start_ind and end_ind:
                break
            if p1.match(line):
                start_ind = i
            if p2.match(line):
                end_ind = i

        if not (start_ind and end_ind):
            logger.error("Wave tables not found")
            return None

        table_data = self.file_data[start_ind + 1: end_ind]

        pattern = re.compile(r'%TableColumns:\s+([0-9]+)')
        columns = None
        for line in table_data:
            match = pattern.search(line)
            if match is not None:
                columns = match.group(1)
                break
        if columns is None:
            logger.error("No wave data found")
            return None

        pattern = re.compile(r'%TableColumnTypes:.+\n')
        column_types = None
        for line in table_data:
            match = pattern.search(line)
            if match is not None:
                column_types = len(match.group(0).split())
                break
        if column_types is None:
            logger.error("TableColumnTypes not found")
            return None

        if str(column_types - 1) != str(columns):
            logger.error("Number of columns does not match TableColumns")
            return None

        return table_type

    def validate_file(self):
        """Checks if the wave file is valid. Performs all of the valid* functions.
    
        :return: True/False
        """
        if not self.valid_ctf_data(): return False
        if not self.valid_site_metadata(): return False
        if not self.valid_timestamp_metadata(): return False
        if not self.valid_timezone_metadata(): return False
        if not self.valid_latlon_metadata(): return False

        # support both "WAVL WVM9" and "WAVL WM11" table types, since they can be identically parsed
        table_type = self.valid_wavl_table()
        if not table_type: return False
        self.table_type = table_type

        return True

    def get_wavl_data(self):
        """Gets the data from the WAVL wave table matching this file's table type.
  
        :return: array of dicts with keys being the column type (TIME,AMP1...) and values being the data
        """
        if self.table_type in self.SUPPORTED_TABLE_TYPES:
            return self._get_table_data(f"%TableType: WAVL {self.table_type}", "%TableEnd:")
        else:
            logger.error('table_type not set, please use validate_file() before invoking get_wavl_data()')
            return []

    def get_process_info(self):
        """Gets the data containing text with 'Process', generally the end of the file.

        :return: dict with keys being the column type (TIME,AMP1...) and values being the data
        """
        data = {}

        pattern = re.compile(r'Process')
        result_lines = []
        for line in self.file_data:
            match = pattern.search(line)
            if match is not None:
                result_lines.append(line)

        if len(result_lines) == 0:
            return data

        for line in result_lines:
            line = line.strip()
            if "ProcessedTimeStamp" in line:
                arr = line.split()
                # If the seconds/minutes equals 60, then change it to 59
                if int(arr[5]) == 60: arr[5] = 59
                if int(arr[6]) == 60: arr[6] = 59
                try:
                    data["ProcessedTimeStamp"] = datetime.datetime(int(arr[1]), int(arr[2]), int(arr[3]), int(arr[4]),
                                                                   int(arr[5]), int(arr[6]),
                                                                   tzinfo=datetime.timezone.utc).timestamp()
                except:
                    logger.error(f"get_process_info(): There was an error processing the timestamp: "
                                  f"{line}.  Skipping line")
                    continue
            else:
                arr = line.split()
                data[arr[1].replace('"', '')] = arr[2]

        return data

    def get_wave_metadata(self, sql=True):
        """Gets the metadata from the file

        :param sql: - True will return the data using the sql column name as the key.  Default true
        :return: array of dicts with keys being the column type (TIME,AMP1...) and values being the data
        """
        data = {}
        start = "%CTF"
        end = "%TableRows"

        if len(self.file_data) == 0: return None

        # get the table data from between the passed in start/end of the table
        start_ind = None
        end_ind = None
        p1 = re.compile(start)
        p2 = re.compile(end)
        for i, line in enumerate(self.file_data):
            if start_ind is not None and end_ind is not None:
                break
            if p1.search(line) is not None:
                start_ind = i
            if p2.search(line) is not None:
                end_ind = i

        # ensure both start and end patterns were found
        if start_ind is None or end_ind is None:
            return None

        table_metadata = self.file_data[start_ind: end_ind]
        if len(table_metadata) == 1: return None

        for line in table_metadata:
            if line == "%%": continue
            line = line.strip("%")
            line = line.strip("%")
            line = line.split(":", 1)
            if len(line) == 2:
                data[line[0]] = line[1].strip()

        if not sql: return data

        data_sql = {}
        for key, value in data.items():
            if "nan" in value:
                logger.warning("invalid entry %s for %s", value, key)
                continue
            arr = value.split()
            if key == "Origin":
                data_sql["lat"] = '%.7f' % (float(arr[0]))
                data_sql["lon"] = '%.7f' % (float(arr[1]))
            elif key == "TimeStamp":
                data_sql["time"] = datetime.datetime(int(arr[0]), int(arr[1]), int(arr[2]), int(arr[3]), int(arr[4]),
                                                     int(arr[5]), tzinfo=datetime.timezone.utc).timestamp()
            elif key == "TimeCoverage":
                data_sql[key] = value
            elif key == "CoastlineSector":
                data_sql[key] = arr[0] + " " + arr[1]
            elif key == "WaveBearingLimits":
                data_sql[key] = arr[0] + " " + arr[1]
            elif key == "WaveUseInnerBragg":
                data_sql[key] = arr[0]
            elif key == "WavesFollowTheWind":
                data_sql[key] = arr[0]
            elif key == "WaveSecondOrderMethod":
                data_sql[key] = arr[0]
            elif key == "WaveMergeMethod":
                data_sql[key] = arr[0]
            elif key == "WaveReductionMethod":
                data_sql[key] = arr[0]
            else:
                data_sql[key] = value

        # end for key, value in data
        return data_sql

    def insert_into_db(self, db, site_id, network_id):
        """Inserts the wave file into the database

        :param db: instance of DataBase with an active connection
        :param site_id: id of the site used for this radial file
        :param network_id: id of the network used for this radial file
        :return: boolean indicating success or failure
        """
        firstwavl = True

        # First get the WAVL data
        wavldatas = self.get_wavl_data()
        processinfo = self.get_process_info()
        metadata = self.get_wave_metadata()

        # For some reason some files include the unit in the value of the 'RangeResolutionKMeters' field, so we
        # need to strip that off so that the float conversion doesn't break. Some sites (e.g. FRDO, SOB2) omit
        # the header entirely, in which case _check_float_value() will insert the column DEFAULT.
        if 'RangeResolutionKMeters' in metadata:
            metadata['RangeResolutionKMeters'] = metadata['RangeResolutionKMeters'].split()[0]

        if self.table_type == 'WM11' or 'WVMT':
            # WM11 tables also include the verbal name of the timezone, when we don't have space for that, so cut it off
            # eg. "UTC" +0.000 0 "Atlantic/Reykjavik" --> "UTC" +0.000 0
            # TODO if this metadata varies we will have to use a different approach, but I think all sites are reporting
            #  in UTC anyways
            new_timezone = metadata['TimeZone'][0:14]
            metadata['TimeZone'] = new_timezone

        # Foreach wavldata (last to first)
        # I only want to use metadata and processinfo if this is the last entry in wavldata
        for wavldata in reversed(wavldatas):
            # Figure out the datetime for the line
            mytime = datetime.datetime(int(wavldata['TYRS']), int(wavldata['TMON']), int(wavldata['TDAY']),
                                       int(wavldata['THRS']), int(wavldata['TMIN']), int(wavldata['TSEC']),
                                       tzinfo=datetime.timezone.utc).timestamp()

            if firstwavl:
                # make my sql insert statement
                myinsertvalues = (
                    f"({site_id}, "
                    f"{network_id}, "
                    f"{mytime}, "
                    f"{self._check_value('CTF', metadata)}, "
                    f"{self._check_value('FileType', metadata)}, "
                    f"{self._check_value('UUID', metadata)}, "
                    f"{self._check_value('Manufacturer', metadata)}, "
                    f"{self._check_value('TimeMarks', metadata)}, "
                    f"{self._check_value('TimeZone', metadata)}, "
                    f"{self._check_float_value('lat', metadata, 6)}, "
                    f"{self._check_float_value('lon', metadata, 6)}, "
                    f"{self._check_value('TimeCoverage', metadata)}, "
                    f"{self._check_float_value('RangeResolutionKMeters', metadata)}, "
                    f"{self._check_float_value('AntennaBearing', metadata, 1)}, "
                    f"{self._check_int_value('RangeCells', metadata)}, "
                    f"{self._check_int_value('DopplerCells', metadata)}, "
                    f"{self._check_float_value('TransmitCenterFreqMHz', metadata, 8)}, "
                    f"{self._check_float_value('TransmitBandwidthKHz', metadata, 8)}, "
                    f"{self._check_float_value('TransmitSweepRateHz', metadata, 8)}, "
                    f"{self._check_value('CoastlineSector', metadata)}, "
                    f"{self._check_int_value('CurrentVelocityLimit', metadata)}, "
                    f"{self._check_int_value('BraggSmoothingPoints', metadata)}, "
                    f"{self._check_int_value('BraggHasSecondOrder', metadata)}, "
                    f"{self._check_float_value('WaveBraggNoiseThreshold', metadata)}, "
                    f"{self._check_float_value('WaveBraggPeakDropOff', metadata)}, "
                    f"{self._check_float_value('WaveBraggPeakNull', metadata)}, "
                    f"{self._check_float_value('MaximumWavePeriod', metadata)}, "
                    f"{self._check_value('WaveBearingLimits', metadata)}, "
                    f"{self._check_int_value('WaveUseInnerBragg', metadata)}, "
                    f"{self._check_int_value('WaveSecondOrderMethod', metadata)}, "
                    f"{self._check_int_value('WavesFollowTheWind', metadata)}, "
                    f"{self._check_float_value('WaveSaturationRatio', metadata)}, "
                    f"{self._check_float_value('WaveHeightLimit', metadata)}, "
                    f"{self._check_float_value('WavePeriodSetLimit', metadata)}, "
                    f"{self._check_value('PatternUUID', metadata)}, "
                    f"{self._check_int_value('WaveMergeMethod', metadata)}, "
                    f"{self._check_int_value('WaveReductionMethod', metadata)}, "
                    f"{self._check_int_value('WaveMinDopplerPoints', metadata)}, "
                    f"{self._check_int_value('WaveMinVectors', metadata)}, "
                    f"{self._check_float_value('WaveMaximumWaveHeight', metadata)}, "
                    f"{self._check_float_value('WaveMaximumWavePeriodChange', metadata)}, "
                    f"{self._check_float_value('WaveOutlierLowerPercentage', metadata)}, "
                    f"{self._check_float_value('WaveOutlierUpperPercentage', metadata)}, "
                    f"{self._check_float_value('MWHT', wavldata)}, "
                    f"{self._check_float_value('MWPD', wavldata)}, "
                    f"{self._check_float_value('WAVB', wavldata)}, "
                    f"{self._check_float_value('WNDB', wavldata)}, "
                    f"{self._check_float_value('PMWH', wavldata)}, "
                    f"{self._check_int_value('ACNT', wavldata)}, "
                    f"{self._check_float_value('DIST', wavldata)}, "
                    f"{self._check_float_value('LOND', wavldata)}, "
                    f"{self._check_float_value('LATD', wavldata)}, "
                    f"{self._check_int_value('RCLL', wavldata)}, "
                    f"{self._check_int_value('WDPT', wavldata)}, "
                    f"{self._check_int_value('MTHD', wavldata)}, "
                    f"{self._check_int_value('FLAG', wavldata)}, "
                    f"{self._check_int_value('WHNM', wavldata)}, "
                    f"{self._check_float_value('WHSD', wavldata)}, "
                    f"{self._check_value('ProcessedTimeStamp', processinfo)}, "
                    f"{self._check_value('WaveModelFilter', processinfo)}, "
                    f"{self._check_value('SpectraToWavesModel', processinfo)}, "
                    f"{self._check_value('WaveModelForFive', processinfo)}, "
                    f"{self._check_value('WaveModelArchiver', processinfo)}, "
                    f"{self._check_value('AnalyzeSpectra', processinfo)})"
                )
                firstwavl = False
            else:
                myinsertvalues = (
                    f"({site_id}, "
                    f"{network_id}, "
                    f"{mytime}, "
                    f"{self._check_value('CTF', metadata)}, "
                    f"{self._check_value('FileType', metadata)}, "
                    f"{self._check_value('UUID', metadata)}, "
                    f"{self._check_value('Manufacturer', metadata)}, "
                    f"{self._check_value('TimeMarks', metadata)}, "
                    f"{self._check_value('TimeZone', metadata)}, "
                    f"{self._check_float_value('lat', metadata, 6)}, "
                    f"{self._check_float_value('lon', metadata, 6)}, "
                    f"DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, "
                    f"DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, "
                    f"DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, "
                    f"DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, "
                    f"{self._check_float_value('MWHT', wavldata)}, "
                    f"{self._check_float_value('MWPD', wavldata)}, "
                    f"{self._check_float_value('WAVB', wavldata)}, "
                    f"{self._check_float_value('WNDB', wavldata)}, "
                    f"{self._check_float_value('PMWH', wavldata)}, "
                    f"{self._check_int_value('ACNT', wavldata)}, "
                    f"{self._check_float_value('DIST', wavldata)}, "
                    f"{self._check_float_value('LOND', wavldata)}, "
                    f"{self._check_float_value('LATD', wavldata)}, "
                    f"{self._check_int_value('RCLL', wavldata)}, "
                    f"{self._check_int_value('WDPT', wavldata)}, "
                    f"{self._check_int_value('MTHD', wavldata)}, "
                    f"{self._check_int_value('FLAG', wavldata)}, "
                    f"{self._check_int_value('WHNM', wavldata)}, "
                    f"{self._check_float_value('WHSD', wavldata)}, "
                    f"DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT)"
                )
            if myinsertvalues:
                sql = (("INSERT into wavefiles ("
                        "`site_id`, `network_id`, `time`, `CTF`, `FileType`, `UUID`, `Manufacturer`, "
                        "`TimeMarks`, `TimeZone`, `lat`, `lon`, `TimeCoverage`, `RangeResolutionKMeters`, "
                        "`AntennaBearing`, `RangeCells`, `DopplerCells`, `TransmitCenterFreqMHz`, "
                        "`TransmitBandwidthKHz`, `TransmitSweepRateHz`, `CoastlineSector`, "
                        "`CurrentVelocityLimit`, `BraggSmoothingPoints`, `BraggHasSecondOrder`, "
                        "`WaveBraggNoiseThreshold`, `WaveBraggPeakDropOff`, `WaveBraggPeakNull`, "
                        "`MaximumWavePeriod`, `WaveBearingLimits`, `WaveUseInnerBragg`, `WaveSecondOrderMethod`, "
                        "`WavesFollowTheWind`, `WaveSaturationRatio`, `WaveHeightLimit`, `WavePeriodSetLimit`, "
                        "`PatternUUID`, `WaveMergeMethod`, `WaveReductionMethod`, `WaveMinDopplerPoints`, "
                        "`WaveMinVectors`, `WaveMaximumWaveHeight`, `WaveMaximumWavePeriodChange`, "
                        "`WaveOutlierLowerPercentage`, `WaveOutlierUpperPercentage`, "
                        "`MWHT`, `MWPD`, `WAVB`, `WNDB`, `PMWH`, `ACNT`, `DIST`, `LOND`, `LATD`, `RCLL`, "
                        "`WDPT`, `MTHD`, `FLAG`, `WHNM`, `WHSD`, `ProcessedTimeStamp`, `WaveModelFilter`, "
                        "`SpectraToWavesModel`, `WaveModelForFive`, `WaveModelArchiver`, `AnalyzeSpectra`) values {}")
                       .format(myinsertvalues))

                try:
                    cur = db.connection.cursor()
                    cur.execute(sql)
                    db.connection.commit()
                except Exception as e:
                    # Trying to insert values if it's already in the DB, skip it and move on
                    # TODO theres no way this is the right way of doing this
                    if e.args[0] == 1062:
                        continue

                    logger.error(f"Unable to insert wavefiles data: {e}")
                    return False

        return True
