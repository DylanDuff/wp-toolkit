import { test } from 'node:test'
import assert from 'node:assert/strict'

import { matchesPhrase, stem, words } from '../../src/zag-filters/search-match.js'

const matches = (sentence, phrase) => matchesPhrase(words(sentence), phrase)

test('practitioner words select their discipline', () => {
	assert.ok(matches('podiatrist', 'Podiatry'))
	assert.ok(matches('a podiatrist in Penrith', 'Podiatry'))
	assert.ok(matches('podiatrists', 'Podiatry'))
	assert.ok(matches('physiotherapist', 'Physiotherapy'))
	assert.ok(matches('psychologist near me', 'Psychology'))
	assert.ok(matches('chiropractor', 'Chiropractic'))
	assert.ok(matches('occupational therapist', 'Occupational therapy'))
})

test('a word start is enough once it is long enough', () => {
	assert.ok(matches('physio', 'Physiotherapy'))
	assert.ok(matches('podi', 'Podiatry'))
	assert.ok(!matches('the', 'Therapy'))
	assert.ok(!matches('myphysio', 'Physiotherapy'))
})

test('exact words match whatever their length', () => {
	assert.ok(matches('accepts NDIS', 'NDIS'))
	assert.ok(matches('dva', 'DVA'))
	assert.ok(matches('Penrith', 'Penrith'))
})

test('multi-word phrases need their words together and in order', () => {
	assert.ok(matches('somewhere in the blue mountains', 'blue mountains'))
	assert.ok(!matches('mountains blue', 'blue mountains'))
	assert.ok(!matches('blue sky mountains', 'blue mountains'))
	assert.ok(!matches('older', 'Older adults'))
})

test('unrelated words do not match', () => {
	assert.ok(!matches('penrith', 'Parramatta'))
	assert.ok(!matches('dentist', 'Podiatry'))
	assert.ok(!matches('', 'Podiatry'))
})

test('stems keep short words whole', () => {
	assert.equal(stem('ndis'), 'ndis')
	assert.equal(stem('older'), 'older')
	assert.equal(stem('podiatry'), stem('podiatrist'))
})
