"""Parse pywikibot transliteration.py and output the _trans dictionary as JSON.

Usage: python convert_trans.py <path-to-transliteration.py>
Output: JSON object to stdout
"""
import ast
import json
import re
import sys


def strip_python_comments(text: str) -> str:
    """Strip Python comments from a string, handling strings properly."""
    result = []
    i = 0
    while i < len(text):
        # Skip string literals (single and double quoted)
        if text[i] in ("'", '"'):
            quote = text[i]
            j = i + 1
            while j < len(text):
                if text[j] == '\\':
                    j += 2
                    continue
                if text[j] == quote:
                    # Check if it's a triple quote
                    if text[j:j+3] == quote * 3:
                        j += 3
                        while j < len(text):
                            if text[j] == '\\':
                                j += 2
                                continue
                            if text[j:j+3] == quote * 3:
                                j += 3
                                break
                            j += 1
                    else:
                        j += 1
                        break
                j += 1
            result.append(text[i:j])
            i = j
        elif text[i] == '#':
            # Comment - skip to end of line
            while i < len(text) and text[i] != '\n':
                i += 1
        else:
            result.append(text[i])
            i += 1
    return ''.join(result)


def extract_dict_var(content: str, var_name: str) -> str | None:
    """Extract the text of a Python dict variable definition."""
    pattern = re.compile(
        rf'^\s*{var_name}\s*=\s*'  # var_name = 
        r'(\{)'  # opening brace
        ,
        re.MULTILINE
    )
    match = pattern.search(content)
    if not match:
        return None
    
    start = match.start(1)
    depth = 0
    for i in range(start, len(content)):
        if content[i] == '{':
            depth += 1
        elif content[i] == '}':
            depth -= 1
            if depth == 0:
                return content[start:i+1]
    return None


def extract_non_ascii_digits(content: str) -> dict:
    """Parse NON_ASCII_DIGITS from the file content."""
    # Find the NON_ASCII_DIGITS dict
    match = re.search(r'^\s*NON_ASCII_DIGITS\s*=\s*(\{)', content, re.MULTILINE)
    if not match:
        return {}
    
    start = match.start(1)
    depth = 0
    for i in range(start, len(content)):
        if content[i] == '{':
            depth += 1
        elif content[i] == '}':
            depth -= 1
            if depth == 0:
                break
    
    dict_text = content[start:i+1]
    # Strip comments and parse
    cleaned = strip_python_comments(dict_text)
    try:
        return ast.literal_eval(cleaned)
    except Exception as e:
        print(f'Warning: failed to parse NON_ASCII_DIGITS: {e}', file=sys.stderr)
        return {}


def main(path: str) -> dict:
    with open(path, encoding='utf-8') as f:
        content = f.read()
    
    # Extract _trans dict text
    trans_text = extract_dict_var(content, '_trans')
    if trans_text is None:
        raise ValueError('could not find _trans dict')
    
    # Strip Python comments
    cleaned = strip_python_comments(trans_text)
    
    # Parse with literal_eval
    trans = ast.literal_eval(cleaned)
    
    # Parse and merge NON_ASCII_DIGITS
    digits = extract_non_ascii_digits(content)
    for digit_str in digits.values():
        for i, char in enumerate(digit_str):
            trans[char] = str(i)
    
    return trans


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(f'Usage: {sys.argv[0]} <path-to-transliteration.py> [output.json]', file=sys.stderr)
        sys.exit(1)
    
    trans = main(sys.argv[1])
    if len(sys.argv) >= 3:
        # Output to file to avoid PowerShell BOM issues
        with open(sys.argv[2], 'w', encoding='utf-8') as f:
            json.dump(trans, f, ensure_ascii=False, sort_keys=False)
            f.write('\n')
    else:
        json.dump(trans, sys.stdout, ensure_ascii=False, sort_keys=False)
        print()
