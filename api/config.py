import configparser
import pathlib
from os import getenv
from obstore.store import S3Store

root = pathlib.Path(__file__).resolve().parent
config = configparser.ConfigParser()
config.read_file(open(root / 'radialdata.ini'))
bucket = getenv('AWS_BUCKET_NAME')
s3_store = S3Store()
