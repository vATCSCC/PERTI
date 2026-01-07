"""
Config Loader for PERTI

Reads database credentials from the existing PHP config file.
"""

import re
from pathlib import Path


def load_php_config(config_path: Path = None) -> dict:
    """
    Parse PHP config.php to extract ADL database credentials.

    Args:
        config_path: Path to config.php. Defaults to ../../load/config.php

    Returns:
        Dictionary with db_host, db_name, db_user, db_pass
    """
    if config_path is None:
        # Default path relative to this file
        config_path = Path(__file__).parent.parent.parent / 'load' / 'config.php'

    config = {
        'db_host': '',
        'db_name': '',
        'db_user': '',
        'db_pass': '',
    }

    if not config_path.exists():
        return config

    content = config_path.read_text(encoding='utf-8')

    # Parse PHP define() statements for ADL_SQL_* constants
    patterns = {
        'db_host': r'define\s*\(\s*["\']ADL_SQL_HOST["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        'db_name': r'define\s*\(\s*["\']ADL_SQL_DATABASE["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        'db_user': r'define\s*\(\s*["\']ADL_SQL_USERNAME["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
        'db_pass': r'define\s*\(\s*["\']ADL_SQL_PASSWORD["\']\s*,\s*["\']([^"\']+)["\']\s*\)',
    }

    for key, pattern in patterns.items():
        match = re.search(pattern, content)
        if match:
            config[key] = match.group(1)

    return config


if __name__ == '__main__':
    config = load_php_config()
    print("Loaded config from PHP:")
    print(f"  Host: {config['db_host']}")
    print(f"  Database: {config['db_name']}")
    print(f"  User: {config['db_user']}")
    print(f"  Password: {'*' * len(config['db_pass']) if config['db_pass'] else '(not set)'}")
