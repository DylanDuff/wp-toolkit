// Bundles the Zag Filters frontend (src/zag-filters/) into the committed asset
// the module enqueues. See docs/zag-filters/README.md ("Frontend").
import { build, context } from 'esbuild'

const watch = process.argv.includes('--watch')

const options = {
	entryPoints: ['src/zag-filters/zag-filters.js'],
	outfile: 'inc/zag-filters/assets/zag-filters.js',
	bundle: true,
	format: 'iife',
	target: ['es2020'],
	minify: !watch,
	sourcemap: watch,
	logLevel: 'info'
}

if (watch) {
	const context_ = await context(options)
	await context_.watch()
} else {
	await build(options)
}
