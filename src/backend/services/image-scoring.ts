import type { ImageScore } from '@/shared/types';

export function scoreImages(imageUrls: string[]): ImageScore[] {
  const scored = imageUrls.map((url, index) => {
    const lower = url.toLowerCase();
    let score = 60;
    const reasons: string[] = [];

    if (index === 0) {
      score += 8;
      reasons.push('CJ provided this image first.');
    }
    if (lower.includes('white') || lower.includes('main')) {
      score += 8;
      reasons.push('URL hints at a clean main image.');
    }
    if (lower.includes('watermark') || lower.includes('logo')) {
      score -= 25;
      reasons.push('Possible watermark/logo signal.');
    }
    if (/\.(jpg|jpeg|png|webp)(\?|$)/.test(lower)) {
      score += 5;
      reasons.push('Uses a standard marketplace image format.');
    }

    return {
      url,
      score: Math.max(0, Math.min(100, score)),
      reasons,
      cropPreview: { mode: 'contain' as const, focalPoint: 'center' as const },
      isRecommendedMain: false,
      aiEnhancementStatus: 'not_configured' as const,
    };
  });

  const best = scored.reduce((winner, image) => (image.score > winner.score ? image : winner), scored[0]);
  return scored.map((image) => ({ ...image, isRecommendedMain: image.url === best?.url }));
}
