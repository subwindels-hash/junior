"""Regression gates for schema-linter ALTER ADD COLUMN handling.
Run: python3 -m unittest discover -s tests/python -v (requires sqlglot).
"""
import importlib.util
import unittest
from pathlib import Path

from sqlglot import parse_one

spec = importlib.util.spec_from_file_location(
    "schema_linter", Path(__file__).resolve().parents[2] / "tools/validate_schema.py"
)
linter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(linter)


class AlterColumnTest(unittest.TestCase):
    def setUp(self):
        linter.errors.clear()
        linter.warnings.clear()
        self.tables = {"users": {"id"}, "currencies": {"id"}}

    def apply(self, sql):
        stmt = parse_one(sql, read="mysql")
        return linter.apply_alter(stmt.this.name, stmt.args["actions"], self.tables)

    def test_added_column_is_available_to_subsequent_fk(self):
        self.apply("ALTER TABLE currencies ADD COLUMN rate_updated_by BIGINT UNSIGNED NULL")
        count = self.apply("ALTER TABLE currencies ADD CONSTRAINT fk_actor FOREIGN KEY (rate_updated_by) REFERENCES users(id)")
        self.assertEqual(1, count)
        self.assertIn("rate_updated_by", self.tables["currencies"])
        self.assertEqual([], linter.errors)

    def test_missing_child_and_parent_columns_still_fail(self):
        self.apply("ALTER TABLE currencies ADD CONSTRAINT fk_actor FOREIGN KEY (missing_actor) REFERENCES users(missing_id)")
        self.assertEqual(2, len(linter.errors))
        self.assertTrue(any("missing_actor" in error for error in linter.errors))
        self.assertTrue(any("missing_id" in error for error in linter.errors))

    def test_house_rules_apply_to_added_columns(self):
        self.apply("ALTER TABLE currencies ADD COLUMN amount INT, ADD COLUMN updated_at TIMESTAMP")
        self.assertEqual(2, len(linter.errors))
        self.assertTrue(any("expected DECIMAL" in error for error in linter.errors))
        self.assertTrue(any("TIMESTAMP" in error for error in linter.errors))

    def test_metadata_and_counts_are_not_money(self):
        self.apply("ALTER TABLE currencies ADD COLUMN rate_source VARCHAR(32)")
        self.tables["marketplace_orders"] = {"id"}
        self.apply("ALTER TABLE marketplace_orders ADD COLUMN refunded_quantity INT UNSIGNED")
        self.assertEqual([], linter.errors)


if __name__ == "__main__":
    unittest.main()
