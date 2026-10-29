// Copies the storefront's bundled web fonts (stage D1; FN-1 adds the two STATIC families Montserrat and Cormorant Garamond from @fontsource) from the @fontsource-variable packages into public/fonts/storefront/{family}/ and writes the
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

// FN-1: static families, one file per weight/style/subset. Montserrat for text, Cormorant Garamond (a serif) for headings. The unicode-range of each file is read from the package's own css, where the
// comment BEFORE a rule names that rule. Subsets: latin, latin-ext, cyrillic (cyrillic-ext is not copied, like the existing families).
const staticFamilies = {
    montserrat: { package: 'montserrat', css: 'Montserrat', serif: false, styles: [['normal', [300, 400, 500, 600]]] },
    cormorant: { package: 'cormorant-garamond', css: 'Cormorant Garamond', serif: true, styles: [['normal', [400, 500, 600]], ['italic', [500]]] },
};
const staticSubsets = ['latin', 'latin-ext', 'cyrillic'];

for (const [key, family] of Object.entries(staticFamilies)) {
    const from = join(root, 'node_modules', '@fontsource', family.package);
    const to = join(root, 'public', 'fonts', 'storefront', key);

    if (! existsSync(from)) {
        console.error(`@fontsource/${family.package} is not installed: run npm install`);
        process.exit(1);
    }

    mkdirSync(to, { recursive: true });
    copyFileSync(join(from, 'LICENSE'), join(to, 'OFL-LICENSE.txt'));

    const faces = [];

    for (const [style, weights] of family.styles) {
        for (const weight of weights) {
            const css = readFileSync(join(from, `${weight}${style === 'italic' ? '-italic' : ''}.css`), 'utf8');

            for (const subset of staticSubsets) {
                const name = `${family.package}-${subset}-${weight}-${style}`;
                const file = `${key}-${subset}-${weight}-${style}.woff2`;
                const block = new RegExp(String.raw`/\*\s*${name}\s*\*/\s*@font-face\s*\{([^}]*)\}`).exec(css);
                const range = block && /unicode-range:\s*([^;]+);/.exec(block[1]);

                if (! range || ! existsSync(join(from, 'files', `${name}.woff2`))) {
                    console.error(`${key}: ${name} was not found`);
                    process.exit(1);
                }

                copyFileSync(join(from, 'files', `${name}.woff2`), join(to, file));
                faces.push({ subset, file, range: range[1].trim(), weight, style });
            }
        }
    }

    manifest[key] = { family: family.css, serif: family.serif, faces };
}

writeFileSync(join(root, 'public', 'fonts', 'storefront', 'fonts.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log('storefront fonts copied: ' + Object.keys(manifest).join(', '));
