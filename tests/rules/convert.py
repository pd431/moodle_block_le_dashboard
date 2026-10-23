#!/usr/bin/env python3
"""Moves Behat scenarios that need no browser into PHPUnit tests under tests/rules/ (beta30, B2).

First, in a feature tagged @javascript as a whole, the tag moves down to the scenarios that need a browser: those
with a sentence that has no twin, or one that only a browser can check (B3). Then a scenario moves when neither
it nor its feature is tagged @javascript or @block_rhb, and every one of its
sentences (Background included) has a twin: a method of the same name in tests/generator/fixture/steps.php (the
browser-free steps the Behat context delegates to), in tests/view_testcase.php (the twins of the steps that read
the page), or one of view_testcase's core sentences. The sentences are matched with the regexes of the Behat
context itself, so the vocabulary has one owner.

Each moved scenario becomes one test method, one step() call per sentence, in tests/rules/<feature>_test.php,
and is cut from the feature file. A feature keeps every scenario that cannot move, and at least one scenario.

    python3 tests/rules/convert.py            # report what would move and what blocks the rest
    python3 tests/rules/convert.py --write    # move them
"""
import glob
import os
import re
import textwrap
import sys
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
CONTEXT = os.path.join(ROOT, 'tests/behat/behat_block_le_dashboard.php')
STEPS = os.path.join(ROOT, 'tests/generator/fixture/steps.php')
TESTCASE = os.path.join(ROOT, 'tests/view_testcase.php')
RULES = os.path.join(ROOT, 'tests/rules')
# As view_testcase::CORE.
CORE = {
    r'^the following "(?P<type>[^"]*)" exist:$': 'the_following_exist',
    r'^the following config values are set as admin:$': 'config_values_are_set',
    r'^I log in as "(?P<user>[^"]*)"$': 'i_log_in_as',
    r'^I should (?P<not>not )?see "(?P<text>(?:[^"]|\\")*)"$': 'i_should_see',
}
CORE_TABLES = {'users', 'courses', 'course enrolments', 'groups', 'group members'}
# Twins that read the rendered page in PHPUnit but need a browser in Behat.
JS_ONLY = {'the_sections_should_be_in_the_order', 'the_grade_should_be_visible'}
HEADER = '''<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace block_le_dashboard\\rules;

use block_le_dashboard\\view_testcase;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../view_testcase.php');

'''


def sentences():
    """The context's step regexes, in order, to the method that runs each."""
    src = open(CONTEXT).read()
    found = []
    for m in re.finditer(r'@(?:Given|When|Then) (/.*?/)\n(?:[^\n]*\n)*?\s*public function (\w+)\(', src):
        regex = m.group(1)[1:-1]
        found.append((regex, m.group(2)))
    return found


def twins():
    names = set()
    for path in (STEPS, TESTCASE):
        names |= set(re.findall(r'^\s+(?:public|protected)(?: static)? function (\w+)\(', open(path).read(), re.M))
    return names


class Resolver:
    def __init__(self):
        self.core = [(re.compile(r), m) for r, m in CORE.items()]
        self.context = [(re.compile(r), m) for r, m in sentences()]
        self.twins = twins()

    def twin(self, text, table):
        """The twin method for a sentence, or None."""
        for regex, method in self.core:
            if regex.match(text):
                if method == 'the_following_exist' and regex.match(text).group('type') not in CORE_TABLES:
                    return None
                return method
        for regex, method in self.context:
            if regex.match(text):
                return method if method in self.twins else None
        return None


def parse_feature(text):
    """Tags, background steps and scenarios of a feature file, each step as (keyword, text, table, line)."""
    lines = text.split('\n')
    feature = {'tags': [], 'background': [], 'scenarios': [], 'lines': lines}
    pending_tags = []
    block = None
    for i, raw in enumerate(lines):
        line = raw.strip()
        if not line or line.startswith('#'):
            continue
        if line.startswith('@'):
            pending_tags = line.split()
            continue
        if line.startswith('Feature:'):
            feature['tags'] = pending_tags
            pending_tags = []
            continue
        if line.startswith('Background:'):
            block = {'steps': feature['background']}
            pending_tags = []
            continue
        m = re.match(r'(Scenario Outline|Scenario):\s*(.*)', line)
        if m:
            tag_line = i - 1 if pending_tags else None
            block = {'title': m.group(2).strip(), 'outline': m.group(1) == 'Scenario Outline', 'tags': pending_tags,
                     'steps': [], 'examples': [], 'start': tag_line if tag_line is not None else i, 'end': i}
            feature['scenarios'].append(block)
            pending_tags = []
            continue
        if block is None:
            continue
        if 'title' in block:
            block['end'] = i
        if line.startswith('Examples:'):
            block['in_examples'] = True
            continue
        if line.startswith('|'):
            cells = [c.strip() for c in line.strip('|').split('|')]
            if block.get('in_examples'):
                block['examples'].append(cells)
            else:
                block['steps'][-1]['table'].append(cells)
            continue
        m = re.match(r'(Given|When|Then|And|But)\s+(.*)', line)
        if m:
            block['steps'].append({'keyword': m.group(1), 'text': m.group(2).strip(), 'table': [], 'line': i})
    return feature


def needs_browser(resolver, feature, sc):
    """Whether a scenario has a sentence without a twin, or one only a browser can check."""
    for step in feature['background'] + sc['steps']:
        text = step['text']
        if sc['outline']:
            text = substitute(text, dict(zip(sc['examples'][0], sc['examples'][1])))
        twin = resolver.twin(text, step['table'])
        if twin is None or twin in JS_ONLY:
            return True
    return False


def retag(resolver, text):
    """Moves a feature-level @javascript tag down to the scenarios that need a browser (B3)."""
    feature = parse_feature(text)
    if '@javascript' not in feature['tags']:
        return text
    lines = text.split('\n')
    first = next(i for i, l in enumerate(lines) if l.strip().startswith('@'))
    lines[first] = ' '.join(t for t in lines[first].split() if t != '@javascript')
    inserted = 0
    for sc in feature['scenarios']:
        if not needs_browser(resolver, feature, sc) or '@javascript' in sc['tags']:
            continue
        i = sc['start'] + inserted
        if sc['tags']:
            lines[i] = lines[i].rstrip() + ' @javascript'
        else:
            indent = re.match(r'\s*', lines[i]).group(0)
            lines.insert(i, indent + '@javascript')
            inserted += 1
    return '\n'.join(lines)


def substitute(text, row):
    for key, value in row.items():
        text = text.replace('<' + key + '>', value)
    return text


def php_string(text):
    return "'" + text.replace('\\', '\\\\').replace("'", "\\'") + "'"


def slug(text):
    s = re.sub(r'[^a-z0-9]+', '_', text.lower()).strip('_')
    return s or 'scenario'


def covers():
    out = []
    for path in sorted(glob.glob(os.path.join(ROOT, 'classes/**/*.php'), recursive=True)):
        if re.search(r'^interface ', open(path).read(), re.M):
            continue
        rel = os.path.relpath(path, os.path.join(ROOT, 'classes'))[:-4]
        out.append(' * @covers     \\block_le_dashboard\\' + rel.replace('/', '\\'))
    return '\n'.join(out)


def wrap(text, width, indent):
    """A docblock paragraph as lines of at most width characters, each starting with indent."""
    return '\n'.join(indent + line for line in textwrap.wrap(text, width - len(indent)))


def sentence_php(text):
    """A sentence as a PHP string, split over lines when one would be too long for the coding style."""
    if len(text) <= 100:
        return php_string(text)
    parts = []
    while len(text) > 100:
        cut = text.rfind(' ', 0, 100) + 1
        parts.append(text[:cut])
        text = text[cut:]
    parts.append(text)
    return '\n            . '.join(php_string(p) for p in parts)


def method_php(name, title, steps, note=''):
    out = ['    /**', wrap(title, 120, '     * ') + ('' if not note else '\n     *\n' + wrap(note, 120, '     * ')),
           '     */', '    public function %s(): void {' % name]
    for step in steps:
        call = '        $this->step(' + sentence_php(step['keyword'] + ' ' + step['text'])
        if step['table']:
            header, data = step['table'][0], step['table'][1:]
            if step['text'].startswith('the following config values are set as admin'):
                # Core's config table has no header row: name, value, plugin.
                header, data = ['config', 'value', 'plugin'], step['table']
            rows = []
            for cells in data:
                pairs = [php_string(h) + ' => ' + php_string(c) for h, c in zip(header, cells)]
                row = '            [' + ', '.join(pairs) + '],'
                if len(row) > 120:
                    row = '            [\n' + '\n'.join('                ' + pair + ',' for pair in pairs) + '\n            ],'
                rows.append(row)
            call += ', [\n' + '\n'.join(rows) + '\n        ]'
        out.append(call + ');')
    out.append('    }')
    return '\n'.join(out)


def main():
    write = '--write' in sys.argv
    resolver = Resolver()
    blockers = Counter()
    report = []
    moved_total = kept_total = 0
    os.makedirs(RULES, exist_ok=True)
    for path in sorted(glob.glob(os.path.join(ROOT, 'tests/behat/*.feature'))):
        text = retag(resolver, open(path).read())
        feature = parse_feature(text)
        name = os.path.basename(path)[:-8]
        feature_js = '@javascript' in feature['tags'] or '@block_rhb' in feature['tags']
        movable = []
        for sc in feature['scenarios']:
            if feature_js or '@javascript' in sc['tags'] or '@block_rhb' in sc['tags']:
                continue
            missing = []
            for step in feature['background'] + sc['steps']:
                sentence = step['text']
                if sc['outline']:
                    # Any example will do for matching: placeholders are quoted values.
                    sentence = substitute(sentence, dict(zip(sc['examples'][0], sc['examples'][1])))
                if resolver.twin(sentence, step['table']) is None:
                    missing.append(step['text'])
            if missing:
                for m in missing:
                    blockers[re.sub(r'"[^"]*"', '"X"', m)] += 1
                continue
            movable.append(sc)
        if movable and len(movable) == len(feature['scenarios']):
            movable = movable[1:]
        kept_total += len(feature['scenarios']) - len(movable)
        moved_total += len(movable)
        if not movable:
            if write:
                open(path, 'w').write(text)
            continue
        methods = []
        used = set()
        for sc in movable:
            base = 'test_' + slug(sc['title'])
            examples = [{}]
            if sc['outline']:
                header = sc['examples'][0]
                examples = [dict(zip(header, row)) for row in sc['examples'][1:]]
            for n, row in enumerate(examples, 1):
                mname = base if len(examples) == 1 else '%s_%d' % (base, n)
                while mname in used:
                    mname += '_'
                used.add(mname)
                steps = [{'keyword': s['keyword'], 'text': substitute(s['text'], row),
                          'table': [[substitute(c, row) for c in cells] for cells in s['table']]}
                         for s in feature['background'] + sc['steps']]
                note = '' if len(examples) == 1 else 'Example: ' + ', '.join('%s = %s' % kv for kv in row.items())
                methods.append(method_php(mname, sc['title'], steps, note))
                report.append((name, sc['title'] + ('' if len(examples) == 1 else ' (%d)' % n), mname))
        title = re.search(r'^Feature:\s*(.*)$', '\n'.join(feature['lines']), re.M).group(1).strip()
        classname = slug(name) + '_test'
        about = 'From tests/behat/' + name + '.feature: each test is one of its scenarios, run by view_testcase one ' \
            'sentence at a time; the sentences that read the page are checked against the rendered dashboard. ' \
            'Every class is covered: a scenario reaches the views through the adapters and the index.'
        php = HEADER + '/**\n * ' + title + '.\n *\n' + wrap(about, 120, ' * ') + '\n *\n * @package    block_le_dashboard\n' \
            ' * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector\'s my_assessment dashboard\n' \
            ' * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later\n' + covers() + '\n */\n' \
            'final class ' + classname + ' extends view_testcase {\n' + '\n\n'.join(methods) + '\n}\n'
        if write:
            open(os.path.join(RULES, classname + '.php'), 'w').write(php)
            # Cut the moved scenarios from the feature, from their tags line to the line before the next block.
            lines = feature['lines']
            cut = set()
            for i, sc in enumerate(feature['scenarios']):
                if sc not in movable:
                    continue
                start = sc['start']
                end = feature['scenarios'][i + 1]['start'] if i + 1 < len(feature['scenarios']) else len(lines)
                # Leave comments that introduce the next scenario with it.
                while end > sc['end'] + 1 and (not lines[end - 1].strip() or lines[end - 1].strip().startswith('#')):
                    end -= 1
                cut |= set(range(start, end))
                # And the blank line before the cut block.
                if start > 0 and not lines[start - 1].strip():
                    cut.add(start - 1)
            kept = [l for i, l in enumerate(lines) if i not in cut]
            open(path, 'w').write('\n'.join(kept))
    print('Scenarios: %d move, %d stay' % (moved_total, kept_total))
    print('\nMoved (feature | scenario | test):')
    for row in report:
        print('  %s | %s | %s' % row)
    print('\nSentences without a twin, by how many scenarios they hold back:')
    for text, n in blockers.most_common(40):
        print('  %3d  %s' % (n, text))


if __name__ == '__main__':
    main()
