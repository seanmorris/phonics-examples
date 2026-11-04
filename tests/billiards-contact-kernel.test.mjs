import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { test } from 'node:test';
import { configureGeometry, contactBatchBase64 } from '../games/php-billiards/browser/contact-kernel.mjs';

const oracle = JSON.parse(
	execFileSync('php', ['tests/fixtures/billiards-contact.php'], { maxBuffer: 8 * 1024 * 1024 })
);
configureGeometry(JSON.stringify(oracle.geometry));
for (const fixture of oracle.fixtures)
	test(`contact backend preserves every PHP double and event: ${fixture.name}`, () => {
		const actual = Buffer.from(contactBatchBase64(fixture.input), 'base64'),
			expected = Buffer.from(fixture.expected, 'base64');
		assert.equal(actual.length, expected.length, 'Packed state/event length differs');
		for (let i = 0; i < actual.length; i += 8)
			assert.equal(
				actual.readBigUInt64LE(i),
				expected.readBigUInt64LE(i),
				`Double ${i / 8}: ${actual.readDoubleLE(i)} versus ${expected.readDoubleLE(i)}`
			);
	});
