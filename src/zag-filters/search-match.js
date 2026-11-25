/**
 * How a typed sentence is matched against option labels and Vocabulary
 * phrases. Pure, no DOM, so it runs under `node --test`
 * (tests/zag-filters/search-match.test.mjs).
 *
 * Matching is word by word. A phrase matches when its words appear, in order
 * and next to each other, among the typed words, where a typed word matches a
 * phrase word when:
 *
 *   - they are the same word: "penrith" → "Penrith"
 *   - the typed word starts the phrase word: "physio" → "Physiotherapy",
 *     which is what lets an option select itself while it is still being typed
 *   - they share a stem: "podiatrist" → "Podiatry", "psychologist" →
 *     "Psychology", "chiropractor" → "Chiropractic"
 *
 * The earlier test was a substring check of the whole label against the typed
 * text, so the label had to be typed in full: "podiatrist" never contains
 * "podiatry", and "physio" never contains "physiotherapy".
 */

/**
 * Typed words shorter than this never match by prefix.
 *
 * Three letters is where English filler starts colliding with labels: "the"
 * starts "Therapy", "old" starts "Older adults". Exact and stem matches are not
 * limited, so a short label such as "DVA" still matches when typed in full.
 */
export const MIN_PREFIX_LENGTH = 4

/** A stem shorter than this is too generic to compare. */
const MIN_STEM_LENGTH = 4

/**
 * Endings stripped before comparing stems, longest first.
 *
 * Tuned for the practitioner-and-discipline shape of label these filters
 * carry: the person ("-ist", "-ian", "-or", "-er") against the discipline
 * ("-y", "-ic", "-ics"), plus plurals.
 */
const SUFFIXES = ['ists', 'ians', 'ist', 'ian', 'ics', 'ors', 'ers', 'ies', 'ic', 'or', 'er', 'y', 's']

/** Lowercase, punctuation to spaces, collapsed. */
export function normalise(text) {
	return String(text)
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, ' ')
		.trim()
}

/** Normalised text as a list of words. */
export function words(text) {
	const normalised = normalise(text)

	return normalised ? normalised.split(' ') : []
}

/** A word without the first ending that leaves enough of it to compare. */
export function stem(word) {
	for (const suffix of SUFFIXES) {
		if (word.endsWith(suffix) && word.length - suffix.length >= MIN_STEM_LENGTH) {
			return word.slice(0, -suffix.length)
		}
	}

	return word
}

/** Whether one typed word reads as one phrase word. */
export function wordMatches(typed, wanted) {
	if (typed === wanted) {
		return true
	}

	if (typed.length >= MIN_PREFIX_LENGTH && wanted.startsWith(typed)) {
		return true
	}

	const typedStem = stem(typed)

	return typedStem.length >= MIN_STEM_LENGTH && typedStem === stem(wanted)
}

/**
 * Whether a phrase appears in the typed words.
 *
 * @param {string[]} typed  Words of the sentence, from words().
 * @param {string}   phrase A label or Vocabulary phrase, raw or normalised.
 */
export function matchesPhrase(typed, phrase) {
	const wanted = words(phrase)

	if (!wanted.length || wanted.length > typed.length) {
		return false
	}

	for (let start = 0; start + wanted.length <= typed.length; start++) {
		if (wanted.every((word, offset) => wordMatches(typed[start + offset], word))) {
			return true
		}
	}

	return false
}
