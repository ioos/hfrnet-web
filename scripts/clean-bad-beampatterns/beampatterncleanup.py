import configparser
import csv
import pymysql


config = configparser.ConfigParser()
config.read_file(open('beampatternconfig.ini'))


def parse_csv():
    siteslist = []

    # use csv to figure out affiliation for provided site
    with open('../data/siteinfo.csv', 'r') as f:
        siteinfo = csv.DictReader(f, delimiter=',')
        for row in siteinfo:
            # if site has a patterntype override, append to siteslist
            if row["patterntype_override"]:
                print(f"{row['patterntype_override']} pattern type override found for site {row['site']}.")
                # if we are overriding the patterntype, we want to remove the other pattern type
                override_type = row["patterntype_override"]
                if override_type == "RDLi":
                    siteslist.append({
                        "site": row["site"],
                        "delete": "RDLm"
                    })
                if override_type == "RDLm":
                    siteslist.append({
                        "site": row["site"],
                        "delete": "RDLm"
                    })

            if row["patterntype_ignore"]:
                print(f"{row['patterntype_ignore']} pattern type ignore found for site {row['site']}.")
                # if we are ignoring a specific pattern type, we want to remove that pattern type specifically
                ignore_type = row["patterntype_ignore"]
                if ignore_type == "RDLi":
                    siteslist.append({
                        "site": row["site"],
                        "delete": "RDLi"
                    })
                if ignore_type == "RDLm":
                    siteslist.append({
                        "site": row["site"],
                        "delete": "RDLm"
                    })

    return siteslist


def clean_metrics_db(sites):
    """Cleans the metrics database using the provided sites list and the pattern type to delete for that site."""
    # connect to metrics db
    mycur = None
    metrics_host = config['metrics']['db_host']
    metrics_user = config['metrics']['db_user']
    metrics_password = config['metrics']['db_password']
    metrics_database = config['metrics']['db']
    metrics_port = int(config['metrics']['db_port'])

    try:
        mycon = pymysql.connect(host=metrics_host,
                                user=metrics_user,
                                password=metrics_password,
                                database=metrics_database,
                                port=metrics_port,
                                charset='utf8mb4',
                                cursorclass=pymysql.cursors.DictCursor)
        mycur = mycon.cursor()
    except pymysql.Error as e:
        try:
            print(f"MySQL Error [{e.args[0]}]: {e.args[1]}")
        except IndexError:
            print(f"MySQL Error: {str(e)}")
        return

    print("connected to metrics database successfully")

    # TODO just clearing metricUptime table for now, since metricFiles would involve parsing the file name column
    for site in sites:
        with mycon.cursor() as cur_data:
            # clear radialfiles table with given pattern type, site id, network id
            sql = (f"DELETE FROM metricUptime m WHERE m.site='{site['site']}' "
                   f"AND m.type='{site['delete']}'")
            try:
                cur_data.execute(sql)
            except pymysql.Error as e:
                code, msg = e.args
                print(f"Failed to clear metricUptime table for {site['site']}: {msg}. Skipping site.")
                continue

    mycon.commit()
    mycon.close()


def clean_hfradar_db(sites):
    """Cleans the hfradar database using the provided sites list and the pattern type to delete for that site."""
    # connect to hfradar db
    mycur = None
    hfradar_host = config['hfradar']['db_host']
    hfradar_user = config['hfradar']['db_user']
    hfradar_password = config['hfradar']['db_password']
    hfradar_database = config['hfradar']['db']
    hfradar_port = int(config['hfradar']['db_port'])

    try:
        mycon = pymysql.connect(host=hfradar_host,
                                user=hfradar_user,
                                password=hfradar_password,
                                database=hfradar_database,
                                port=hfradar_port,
                                charset='utf8mb4',
                                cursorclass=pymysql.cursors.DictCursor)
        mycur = mycon.cursor()
    except pymysql.Error as e:
        try:
            print(f"MySQL Error [{e.args[0]}]: {e.args[1]}")
        except IndexError:
            print(f"MySQL Error: {str(e)}")
        return

    print("connected to hfradar database successfully")

    for site in sites:
        delete_pattern = None
        if site['delete'] == "RDLm":
            delete_pattern = "m"
        if site['delete'] == "RDLi":
            delete_pattern = "i"

        # get site / network id for this site
        with mycon.cursor() as cur_data:
            sql = f"SELECT s.site_id, s.network_id FROM site s WHERE sta='{site['site']}'"
            try:
                cur_data.execute(sql)
                rs = cur_data.fetchone()
            except pymysql.Error as e:
                code, msg = e.args
                print(f"Unable to retrieve station/network id for {site['site']}: {msg}. Skipping site.")
                continue

            if rs and rs['site_id'] and rs['network_id']:
                site_id = rs['site_id']
                network_id = rs['network_id']
            else:
                print(f"Unable to retrieve station/network id for {site['site']}. Skipping site.")
                continue

            # clear radialfiles table with given pattern type, site id, network id
            sql = (f"DELETE FROM radialfiles r WHERE r.site_id={site_id} "
                   f"AND r.network_id={network_id} "
                   f"AND r.patterntype='{delete_pattern}'")
            try:
                cur_data.execute(sql)
            except pymysql.Error as e:
                code, msg = e.args
                print(f"Failed to clear radialfiles table for {site['site']}: {msg}. Skipping site.")
                continue

            # clear radialdiag table with given pattern type, site id, network id
            sql = (f"DELETE FROM radialdiag r WHERE r.site_id={site_id} "
                   f"AND r.network_id={network_id} "
                   f"AND r.patterntype='{delete_pattern}'")
            try:
                cur_data.execute(sql)
            except pymysql.Error as e:
                code, msg = e.args
                print(f"Failed to clear radialdiag table for {site['site']}: {msg}. Skipping site.")
                continue

            # clear latest_radialfiles table
            sql = (f"DELETE FROM latest_radialfiles r WHERE r.site_id='{site_id}' "
                   f"AND r.network_id='{network_id}' "
                   f"AND r.patterntype='{delete_pattern}'")
            try:
                cur_data.execute(sql)
            except pymysql.Error as e:
                code, msg = e.args
                print(f"Failed to clear latest_radialfiles table for {site['site']}: {msg}. Skipping site.")
                continue

            # clear latest_radialdiag table
            sql = (f"DELETE FROM latest_radialdiag r WHERE r.site_id='{site_id}' "
                   f"AND r.network_id='{network_id}' "
                   f"AND r.patterntype='{delete_pattern}'")
            try:
                cur_data.execute(sql)
            except pymysql.Error as e:
                code, msg = e.args
                print(f"Failed to clear latest_radialdiag table for {site['site']}: {msg}. Skipping site.")
                continue

    mycon.commit()
    mycon.close()


if __name__ == "__main__":

    # parse siteinfo.csv to build list of sites that need to be cleaned
    sites = parse_csv()

    # clean metrics database
    clean_metrics_db(sites)

    # clean hfradar database
    clean_hfradar_db(sites)
