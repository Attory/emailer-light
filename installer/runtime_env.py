"""Literal systemd/Dotenv-compatible settings; never evaluate shell input."""
import json
import re


class RuntimeEnvironmentError(Exception):
    pass


def parse_environment_text(text):
    result = {}
    for line in text.splitlines():
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        if '=' not in line:
            raise RuntimeEnvironmentError('The saved hosting settings have an unsupported line.')
        key, value = line.split('=', 1)
        if not re.fullmatch(r'[A-Z][A-Z0-9_]*', key) or key in result:
            raise RuntimeEnvironmentError('The saved hosting settings contain duplicate or invalid names.')
        if value.startswith("'") and value.endswith("'"):
            value = value[1:-1]
            if "'" in value:
                raise RuntimeEnvironmentError('The saved hosting settings use an unsupported quoted value.')
        elif value.startswith('"'):
            try:
                if re.search(r'(?<!\\)\$[A-Za-z_{]', value):
                    raise RuntimeEnvironmentError('Expanded environment variables are unsupported in saved hosting settings.')
                value = json.loads(value.replace('\\$', '$'))
            except ValueError:
                raise RuntimeEnvironmentError('The saved hosting settings use an unsupported quoted value.') from None
            if not isinstance(value, str):
                raise RuntimeEnvironmentError('The saved hosting settings contain a non-text value.')
        else:
            if value.startswith("'") or value.startswith('"'):
                raise RuntimeEnvironmentError('The saved hosting settings contain an unmatched quote.')
            if any(character.isspace() for character in value) or re.search(r'\$[A-Za-z_{]', value):
                raise RuntimeEnvironmentError('The saved hosting settings contain an ambiguous unquoted value.')
        if not isinstance(value, str) or '\x00' in value or '\n' in value or '\r' in value:
            raise RuntimeEnvironmentError('The saved hosting settings contain an invalid value.')
        result[key] = value
    return result
