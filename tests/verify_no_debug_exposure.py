import os
import sys

def verify_no_debug_exposure():
    file_path = 'assets/js/client-portal.js'
    forbidden_string = 'window.ApertureClientUploader'

    if not os.path.exists(file_path):
        print(f"Error: {file_path} does not exist.")
        sys.exit(1)

    with open(file_path, 'r') as f:
        content = f.read()

    if forbidden_string in content:
        print(f"FAILURE: Found debug exposure '{forbidden_string}' in {file_path}")
        sys.exit(1)

    print(f"SUCCESS: '{forbidden_string}' not found in {file_path}")
    sys.exit(0)

if __name__ == "__main__":
    verify_no_debug_exposure()
