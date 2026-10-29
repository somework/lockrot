"""The descriptions of the schema number 2 files stay true where a builder copies a schema number 1 node.

The builders deep-copy report-1 and explain-1 nodes, and each copy carries its 0.13 description.
inherited-descriptions.json lists every copied description that was read against the number 2
document and holds for it. A copy that the list does not name fails, so a new copy gets a review,
and a listed path that is no copy any more fails too. A description that pairs a field with a value
(`libyears_unmeasured` `unavailable`) must name a value that the field's known values hold. The
description of a node with known values must name only ids that some value, field or definition of
the schema holds.
"""

import json
import os
import re
import unittest

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..')
LEDGER = os.path.join(ROOT, 'tools', 'schema', 'inherited-descriptions.json')
SOURCES = {
    'lockrot-report-2.schema.json': ['lockrot-report.schema.json'],
    'lockrot-explain-2.schema.json': ['lockrot-explain.schema.json', 'lockrot-report.schema.json'],
}
PAIR = re.compile(r'`([a-z][a-z0-9_.]*)` `([a-z][a-z0-9_-]*)`')
TOKEN = re.compile(r'`([a-z][a-z0-9_]*)`')
# Ids that a description of a closed or known set names and that are no value or field of schema 2:
# Composer's severity word, a PHP function, and two report-1 field names.
NOT_SCHEMA_IDS = {'moderate', 'strcmp', 'previous_verdict', 'flagged_verdicts'}


def load(*path):
    with open(os.path.join(ROOT, *path), encoding='utf-8') as handle:
        return json.load(handle)


def descriptions(node, pointer='#'):
    """Each description of the schema, keyed by the pointer of the node that holds it."""
    found = {}
    if isinstance(node, dict):
        for key, value in node.items():
            if key == 'description' and isinstance(value, str):
                found[pointer] = value
            else:
                found.update(descriptions(value, pointer + '/' + key))
    elif isinstance(node, list):
        for index, value in enumerate(node):
            found.update(descriptions(value, pointer + '/' + str(index)))
    return found


def copied(schema):
    inherited = set()
    for source in SOURCES[schema]:
        inherited |= set(descriptions(load('tools', 'schema', 'inputs', source)).values())
    return sorted(pointer for pointer, text in descriptions(load('resources', schema)).items() if text in inherited)


def values(schema, node, seen=()):
    """The string values that a property schema admits or knows, through `$ref`, `oneOf`, `anyOf` and `items`."""
    if not isinstance(node, dict):
        return set()
    found = {v for key in ('enum', 'x-known-values') for v in node.get(key, []) if isinstance(v, str)}
    if isinstance(node.get('const'), str):
        found.add(node['const'])
    ref = node.get('$ref')
    if isinstance(ref, str) and ref.startswith('#/definitions/') and ref not in seen:
        found |= values(schema, schema['definitions'][ref.split('/')[-1]], seen + (ref,))
    for key in ('oneOf', 'anyOf', 'allOf'):
        for each in node.get(key, []):
            found |= values(schema, each, seen)
    return found | values(schema, node.get('items'), seen)


def unknown_ids(schema):
    """Each node with known values whose description names an id that no value, field or definition of the schema holds."""
    known = known_by_field(schema)
    ids = set().union(*known.values()) | set(known) | set(schema.get('definitions', {})) | NOT_SCHEMA_IDS
    found = {}

    def walk(node, pointer):
        if isinstance(node, dict):
            own = values(schema, node)
            text = node.get('description')
            if own and isinstance(text, str):
                missing = [token for token in TOKEN.findall(text) if token not in own and token not in ids]
                if missing:
                    found[pointer] = missing
            for key, value in node.items():
                walk(value, pointer + '/' + key)
        elif isinstance(node, list):
            for index, value in enumerate(node):
                walk(value, pointer + '/' + str(index))

    walk(schema, '#')
    return found


def known_by_field(schema):
    """Each property name with the values that some property of that name knows. An empty set: the field has no closed or known set."""
    known = {}

    def walk(node):
        if isinstance(node, dict):
            for name, child in (node.get('properties') or {}).items():
                known.setdefault(name, set()).update(values(schema, child))
            for value in node.values():
                walk(value)
        elif isinstance(node, list):
            for value in node:
                walk(value)

    walk(schema)
    return known


class InheritedDescriptionsTest(unittest.TestCase):
    def test_every_copied_description_is_one_the_ledger_lists(self):
        ledger = load('tools', 'schema', 'inherited-descriptions.json')

        self.assertEqual(sorted(SOURCES), sorted(ledger))
        for schema in SOURCES:
            with self.subTest(schema=schema):
                self.assertEqual(sorted(ledger[schema]), copied(schema),
                                 'read each new copy against the number 2 document: list it in inherited-descriptions.json, or give it a new description')

    def test_a_field_named_with_a_value_knows_that_value(self):
        for schema_name in SOURCES:
            schema = load('resources', schema_name)
            known = known_by_field(schema)
            for pointer, text in descriptions(schema).items():
                for field, value in PAIR.findall(text):
                    name = field.split('.')[-1]
                    if known.get(name):
                        with self.subTest(schema=schema_name, pointer=pointer, field=field):
                            self.assertIn(value, known[name], text)

    def test_a_description_of_known_values_names_only_ids_that_the_schema_holds(self):
        for schema_name in SOURCES:
            with self.subTest(schema=schema_name):
                self.assertEqual({}, unknown_ids(load('resources', schema_name)))


class TheFieldValueCheck(unittest.TestCase):
    SCHEMA = {
        'properties': {'reason': {'$ref': '#/definitions/reason'}, 'free': {'type': 'string'}},
        'definitions': {'reason': {'oneOf': [{'type': 'string', 'x-known-values': ['unavailable', 'not_found']}, {'type': 'null'}]}},
    }

    def test_the_values_of_a_field_follow_its_reference(self):
        self.assertEqual({'unavailable', 'not_found'}, known_by_field(self.SCHEMA)['reason'])

    def test_a_field_without_known_values_gives_an_empty_set(self):
        self.assertEqual(set(), known_by_field(self.SCHEMA)['free'])

    def test_a_listed_value_that_the_set_lost_is_an_unknown_id(self):
        schema = {'properties': {'reason': {'description': '`offline` or `composer_too_old`, or a `free` one.', 'x-known-values': ['offline']},
                                 'free': {'type': 'string'}}}
        self.assertEqual({'#/properties/reason': ['composer_too_old']}, unknown_ids(schema))

    def test_a_pair_names_the_field_and_the_value(self):
        self.assertEqual([('libyears_unmeasured', 'metadata_unavailable')],
                         PAIR.findall('Each such finding is `unknown`, with `libyears_unmeasured` `metadata_unavailable`.'))


if __name__ == '__main__':
    unittest.main()
