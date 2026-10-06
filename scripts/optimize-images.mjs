import sharp from 'sharp';

const jobs = [
  ['src/assets/botanical-hero.png', 'src/assets/botanical-hero.webp', { quality: 78, effort: 6 }],
  ['src/assets/product-sprite.png', 'src/assets/product-sprite.webp', { quality: 82, effort: 6, alphaQuality: 90 }],
  ['src/assets/logo-botanical-crest.png', 'src/assets/logo-botanical-crest.webp', { quality: 88, effort: 6, alphaQuality: 100 }]
];

await Promise.all(jobs.map(async ([input, output, options]) => {
  await sharp(input).webp(options).toFile(output);
  process.stdout.write(`Optimized ${output}\n`);
}));
