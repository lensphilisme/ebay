import type { EbayComparableListing, ImageScore, OptimizationRecommendation } from '@/shared/types';

export interface AIProvider {
  generateTitle(input: { sourceTitle: string; itemSpecifics: Record<string, string>; maxLength: number }): Promise<string>;
  generateDescription(input: { title: string; bullets: string[]; itemSpecifics: Record<string, string> }): Promise<string>;
  extractItemSpecifics(input: { title: string; description?: string; raw: Record<string, unknown> }): Promise<Record<string, string>>;
  scoreImages(input: { imageUrls: string[] }): Promise<ImageScore[]>;
  compareMarketListings(input: { sourceTitle: string; listings: EbayComparableListing[] }): Promise<{ confidence: number; reason: string }>;
  recommendOptimization(input: { listingId: string; metrics: Record<string, number> }): Promise<OptimizationRecommendation>;
}

export class RuleBasedAIProvider implements AIProvider {
  async generateTitle(input: { sourceTitle: string; itemSpecifics: Record<string, string>; maxLength: number }): Promise<string> {
    const usefulSpecifics = ['Brand', 'Type', 'Color', 'Size', 'Material']
      .map((key) => input.itemSpecifics[key])
      .filter(Boolean)
      .join(' ');
    return `${input.sourceTitle} ${usefulSpecifics}`.replace(/\s+/g, ' ').trim().slice(0, input.maxLength);
  }

  async generateDescription(input: { title: string; bullets: string[]; itemSpecifics: Record<string, string> }): Promise<string> {
    const specifics = Object.entries(input.itemSpecifics).map(([key, value]) => `${key}: ${value}`).join('\n');
    return `${input.title}\n\nFeatures:\n${input.bullets.map((bullet) => `- ${bullet}`).join('\n')}\n\nItem specifics:\n${specifics}`;
  }

  async extractItemSpecifics(input: { title: string; raw: Record<string, unknown> }): Promise<Record<string, string>> {
    const raw = input.raw;
    return {
      Brand: String(raw.brandName ?? raw.brand ?? 'Unbranded'),
      Type: String(raw.productType ?? raw.categoryName ?? 'Dropshipping Product'),
      Model: String(raw.model ?? ''),
      Color: String(raw.color ?? ''),
      Size: String(raw.size ?? ''),
      Material: String(raw.material ?? ''),
    };
  }

  async scoreImages(input: { imageUrls: string[] }): Promise<ImageScore[]> {
    const { scoreImages } = await import('@/backend/services/image-scoring');
    return scoreImages(input.imageUrls);
  }

  async compareMarketListings(): Promise<{ confidence: number; reason: string }> {
    return { confidence: 0.5, reason: 'Rule-based provider defers numeric matching to market-research service.' };
  }

  async recommendOptimization(input: { listingId: string; metrics: Record<string, number> }): Promise<OptimizationRecommendation> {
    return {
      listingId: input.listingId,
      action: 'none',
      severity: 'info',
      mode: 'approval',
      requiresApproval: true,
      reason: 'No external AI provider configured; rule-based optimizer will handle recommendations.',
      preview: [],
    };
  }
}
