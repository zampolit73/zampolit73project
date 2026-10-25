"""Subprocess boundary for untrusted public documents; no macro/code execution."""
import json
import sys
from pathlib import Path
from .extract import extract


def main():
    try:
        import resource
        resource.setrlimit(resource.RLIMIT_AS, (768 * 1024 ** 2, 768 * 1024 ** 2))
        resource.setrlimit(resource.RLIMIT_CPU, (35, 35))
    except (ImportError, ValueError, OSError):
        pass  # Windows still has the parent wall-clock timeout.
    try:
        result = extract(Path(sys.argv[1]).read_bytes(), sys.argv[3]).to_dict()
    except ImportError as e:
        result = {'units': [], 'warnings': ['missing_dependency:' + (e.name or 'unknown')], 'status': 'missing_dependency'}
    except Exception as e:
        result = {'units': [], 'warnings': ['parse_error:' + type(e).__name__], 'status': 'parse_error'}
    Path(sys.argv[2]).write_text(json.dumps(result, ensure_ascii=False), encoding='utf-8')


if __name__ == '__main__':
    main()
