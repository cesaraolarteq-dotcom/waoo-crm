#!/usr/bin/env python3
"""Inject docs/progress/manifest.json into the prototype's temporary progress tab.
Usage: python3 docs/progress/render.py [prototype.html]  (default docs/design/prototype.html)"""
import json, os, re, sys
root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
manifest = json.load(open(os.path.join(root, 'docs/progress/manifest.json')))
target = sys.argv[1] if len(sys.argv) > 1 else os.path.join(root, 'docs/design/prototype.html')
html = open(target).read()
data = json.dumps(manifest, ensure_ascii=False, separators=(',', ':')).replace('</', '<\\/')
new, n = re.subn(r'/\*PROGRESS-START\*/.*?/\*PROGRESS-END\*/', lambda m: '/*PROGRESS-START*/var PROGRESS=' + data + ';/*PROGRESS-END*/', html, flags=re.S)
assert n == 1, 'progress markers not found'
open(target, 'w').write(new)
print('rendered', target)
