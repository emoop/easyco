// Copies the storefront's bundled web fonts (stage D1) from the @fontsource-variable packages into public/fonts/storefront/{family}/ and writes the
// manifest the AppearanceCompiler reads (public/fonts/storefront/fonts.json). Run by `npm run build` (the `prebuild` script); the copied files are
// COMMITTED, so a server needs no node to serve them. Only the latin and cyrillic subsets are copied (the shop speaks Bulgarian and English); the
// licence (SIL Open Font License) of each family is copied next to its files. Fonts are never loaded from another host (privacy, no extra DNS).
import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const families = {
    inter: { package: 'inter', css: 'Inter Variable' },
    manrope: { package: 'manrope', css: 'Manrope Variable' },
    lora: { package: 'lora', css: 'Lora Variable' },
};
const subsets = ['latin', 'cyrillic'];
const manifest = {};

for (const [key, family] of Object.entries(families)) {
    const from = join(root, 'node_modules', '@fontsource-variable', family.package);
    const to = join(root, 'public', 'fonts', 'storefront', key);

    if (! existsSync(from)) {
        console.error(`@fontsource-variable/${family.package} is not installed: run npm install`);
        process.exit(1);
    }

    mkdirSync(to, { recursive: true });
    copyFileSync(join(from, 'LICENSE'), join(to, 'OFL-LICENSE.txt'));

    const css = readFileSync(join(from, 'wght.css'), 'utf8');
    const faces = [];

    for (const subset of subsets) {
        const file = `${key}-${subset}-wght-normal.woff2`;
        const block = css.split('@font-face').find((part) => part.includes(`/* ${key}-${subset}-wght-normal */`) || part.includes(`files/${file}`));
        const range = block && /unicode-range:\s*([^;]+);/.exec(block);

        if (! block || ! range || ! existsSync(join(from, 'files', file))) {
            console.error(`${key}: the ${subset} subset was not found`);
            process.exit(1);
        }

        copyFileSync(join(from, 'files', file), join(to, file));
        faces.push({ subset, file, range: range[1].trim() });
    }

    manifest[key] = { family: family.css, faces };
}

writeFileSync(join(root, 'public', 'fonts', 'storefront', 'fonts.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log('storefront fonts copied: ' + Object.keys(manifest).join(', '));
