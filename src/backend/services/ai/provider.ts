import type { EbayComparableListing, ImageScore, OptimizationRecommendation } from '@/shared/types';
import { getConfig } from '@/backend/config/env';

export interface AIProvider {
  generateTitle(input: { sourceTitle: string; itemSpecifics: Record<string, string>; maxLength: number }): Promise<string>;
  generateDescription(input: { title: string; bullets: string[]; itemSpecifics: Record<string, string> }): Promise<string>;
  extractItemSpecifics(input: { title: string; description?: string; raw: Record<string, unknown> }): Promise<Record<string, string>>;
  extractEbayCategorySpecifics(input: {
    title: string;
    description?: string;
    raw: Record<string, unknown>;
    categoryId: string;
    categoryName?: string;
    aspects: Array<{ name: string; required: boolean; values: string[]; variationEnabled: boolean }>;
    existingSpecifics: Record<string, string>;
    variationKeys: string[];
  }): Promise<Record<string, string>>;
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

  async extractEbayCategorySpecifics(input: {
    title: string;
    raw: Record<string, unknown>;
    aspects: Array<{ name: string; required: boolean; values: string[] }>;
    existingSpecifics: Record<string, string>;
    variationKeys: string[];
  }): Promise<Record<string, string>> {
    const base = await this.extractItemSpecifics({ title: input.title, raw: input.raw });
    const source = { ...base, ...input.existingSpecifics };
    const specifics: Record<string, string> = {};
    for (const aspect of input.aspects) {
      if (input.variationKeys.includes(aspect.name)) continue;
      const direct = source[aspect.name] ?? source[aspect.name.replace(/\s+/g, '')] ?? source[aspect.name.toLowerCase()];
      if (direct) specifics[aspect.name] = String(direct);
    }
    return specifics;
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

export function createAIProvider(): AIProvider {
  const config = getConfig().ai;
  const providers: AIProvider[] = [];
  if (config.openaiApiKey || (config.provider === 'openai' && config.apiKey)) {
    providers.push(new OpenAIProvider(config.openaiApiKey ?? config.apiKey ?? '', config.openaiModel ?? config.model ?? 'gpt-4o-mini'));
  }
  if (config.geminiApiKey || (config.provider === 'gemini' && config.apiKey)) {
    providers.push(new GeminiProvider(config.geminiApiKey ?? config.apiKey ?? '', config.geminiModel ?? config.model ?? 'gemini-1.5-flash'));
  }
  providers.push(new RuleBasedAIProvider());
  return new FallbackAIProvider(providers);
}

class FallbackAIProvider implements AIProvider {
  constructor(private readonly providers: AIProvider[]) {}

  generateTitle(input: { sourceTitle: string; itemSpecifics: Record<string, string>; maxLength: number }): Promise<string> {
    return this.try((provider) => provider.generateTitle(input));
  }
  generateDescription(input: { title: string; bullets: string[]; itemSpecifics: Record<string, string> }): Promise<string> {
    return this.try((provider) => provider.generateDescription(input));
  }
  extractItemSpecifics(input: { title: string; description?: string; raw: Record<string, unknown> }): Promise<Record<string, string>> {
    return this.try((provider) => provider.extractItemSpecifics(input));
  }
  extractEbayCategorySpecifics(input: {
    title: string;
    description?: string;
    raw: Record<string, unknown>;
    categoryId: string;
    categoryName?: string;
    aspects: Array<{ name: string; required: boolean; values: string[]; variationEnabled: boolean }>;
    existingSpecifics: Record<string, string>;
    variationKeys: string[];
  }): Promise<Record<string, string>> {
    return this.try((provider) => provider.extractEbayCategorySpecifics(input));
  }
  scoreImages(input: { imageUrls: string[] }): Promise<ImageScore[]> {
    return this.try((provider) => provider.scoreImages(input));
  }
  compareMarketListings(input: { sourceTitle: string; listings: EbayComparableListing[] }): Promise<{ confidence: number; reason: string }> {
    return this.try((provider) => provider.compareMarketListings(input));
  }
  recommendOptimization(input: { listingId: string; metrics: Record<string, number> }): Promise<OptimizationRecommendation> {
    return this.try((provider) => provider.recommendOptimization(input));
  }
  private async try<T>(call: (provider: AIProvider) => Promise<T>): Promise<T> {
    let lastError: unknown;
    for (const provider of this.providers) {
      try {
        return await call(provider);
      } catch (error) {
        lastError = error;
      }
    }
    throw lastError instanceof Error ? lastError : new Error('AI provider chain failed.');
  }
}

class OpenAIProvider extends RuleBasedAIProvider {
  constructor(private readonly apiKey: string, private readonly model: string) {
    super();
  }

  async generateTitle(input: { sourceTitle: string; itemSpecifics: Record<string, string>; maxLength: number }): Promise<string> {
    return (await this.generateText(`Rewrite this CJ product title for eBay SEO. Max ${input.maxLength} characters. Keep factual, no keyword stuffing, no forbidden claims. Return only the title.\n\nTitle: ${input.sourceTitle}\nSpecifics: ${JSON.stringify(input.itemSpecifics)}`)).slice(0, input.maxLength);
  }

  async generateDescription(input: { title: string; bullets: string[]; itemSpecifics: Record<string, string> }): Promise<string> {
    return this.generateText(`Create a clean eBay description with short buyer-focused paragraphs, bullet features, package contents, and item specifics. No fake claims.\n\nTitle: ${input.title}\nBullets: ${input.bullets.join('; ')}\nSpecifics: ${JSON.stringify(input.itemSpecifics)}`);
  }

  async extractItemSpecifics(input: { title: string; description?: string; raw: Record<string, unknown> }): Promise<Record<string, string>> {
    const text = await this.generateText(`Extract eBay item specifics as compact JSON object. Include Brand, Type, Model, Color, Size, Material, Features, Compatible Brand/Model when relevant. Return JSON only.\n\nTitle: ${input.title}\nDescription: ${input.description ?? ''}\nRaw: ${JSON.stringify(input.raw).slice(0, 6000)}`);
    return parseJsonObject(text);
  }

  async extractEbayCategorySpecifics(input: {
    title: string;
    description?: string;
    raw: Record<string, unknown>;
    categoryId: string;
    categoryName?: string;
    aspects: Array<{ name: string; required: boolean; values: string[]; variationEnabled: boolean }>;
    existingSpecifics: Record<string, string>;
    variationKeys: string[];
  }): Promise<Record<string, string>> {
    const aspects = input.aspects.map((aspect) => ({
      name: aspect.name,
      required: aspect.required,
      allowedValues: aspect.values.slice(0, 80),
    }));
    const text = await this.generateText(`You are filling ACTUAL eBay ItemSpecifics, not description text. Return one compact JSON object whose keys exactly match eBay aspect names. Fill as many eBay aspects as the product data truthfully supports, including optional recommended fields. Do not include variation keys: ${input.variationKeys.join(', ') || '(none)'}. If an aspect has allowedValues, choose one exact allowed value when possible. Prefer factual CJ data; use "Unbranded" for Brand when unknown and "Does not apply" only for part/model numbers where appropriate. Do not invent certification, compatibility, material, brand, model, country, or dimensions unless present in the title, description, images text, raw CJ data, or existing specifics.

Category: ${input.categoryId} ${input.categoryName ?? ''}
Title: ${input.title}
Existing specifics: ${JSON.stringify(input.existingSpecifics)}
eBay aspects: ${JSON.stringify(aspects)}
Description: ${(input.description ?? '').slice(0, 3000)}
Raw CJ: ${JSON.stringify(input.raw).slice(0, 7000)}

JSON only.`);
    return parseJsonObject(text);
  }

  private async generateText(prompt: string): Promise<string> {
    if (!this.apiKey) throw new Error('Missing OpenAI API key.');
    const response = await fetch('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${this.apiKey}` },
      body: JSON.stringify({ model: this.model, messages: [{ role: 'user', content: prompt }], temperature: 0.4 }),
    });
    const data = await response.json() as { choices?: Array<{ message?: { content?: string } }>; error?: { message?: string } };
    if (!response.ok) throw new Error(data.error?.message ?? 'OpenAI request failed.');
    return String(data.choices?.[0]?.message?.content ?? '').trim();
  }
}

class GeminiProvider extends RuleBasedAIProvider {
  constructor(private readonly apiKey: string, private readonly model: string) {
    super();
  }

  async generateTitle(input: { sourceTitle: string; itemSpecifics: Record<string, string>; maxLength: number }): Promise<string> {
    return (await this.generateText(`Rewrite this CJ product title for eBay SEO. Max ${input.maxLength} characters. Return only the title.\nTitle: ${input.sourceTitle}\nSpecifics: ${JSON.stringify(input.itemSpecifics)}`)).slice(0, input.maxLength);
  }

  async generateDescription(input: { title: string; bullets: string[]; itemSpecifics: Record<string, string> }): Promise<string> {
    return this.generateText(`Create a clean eBay product description. Title: ${input.title}. Bullets: ${input.bullets.join('; ')}. Specifics: ${JSON.stringify(input.itemSpecifics)}`);
  }

  async extractItemSpecifics(input: { title: string; description?: string; raw: Record<string, unknown> }): Promise<Record<string, string>> {
    const text = await this.generateText(`Extract eBay item specifics as JSON only.\nTitle: ${input.title}\nDescription: ${input.description ?? ''}\nRaw: ${JSON.stringify(input.raw).slice(0, 6000)}`);
    return parseJsonObject(text);
  }

  async extractEbayCategorySpecifics(input: {
    title: string;
    description?: string;
    raw: Record<string, unknown>;
    categoryId: string;
    categoryName?: string;
    aspects: Array<{ name: string; required: boolean; values: string[]; variationEnabled: boolean }>;
    existingSpecifics: Record<string, string>;
    variationKeys: string[];
  }): Promise<Record<string, string>> {
    const text = await this.generateText(`Fill actual eBay ItemSpecifics as JSON only. Use keys exactly from these eBay aspects. Fill as many required and optional aspects as the product data truthfully supports. Do not include variation keys ${input.variationKeys.join(', ') || '(none)'}. Choose exact allowed values when supplied. Do not invent certification, compatibility, material, brand, model, country, or dimensions unless present in the provided product data.
Category ${input.categoryId} ${input.categoryName ?? ''}
Title ${input.title}
Existing ${JSON.stringify(input.existingSpecifics)}
Aspects ${JSON.stringify(input.aspects.map((aspect) => ({ name: aspect.name, required: aspect.required, allowedValues: aspect.values.slice(0, 80) })))}
Description ${(input.description ?? '').slice(0, 3000)}
Raw ${JSON.stringify(input.raw).slice(0, 7000)}`);
    return parseJsonObject(text);
  }

  private async generateText(prompt: string): Promise<string> {
    if (!this.apiKey) throw new Error('Missing Gemini API key.');
    const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(this.model)}:generateContent?key=${encodeURIComponent(this.apiKey)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ contents: [{ parts: [{ text: prompt }] }] }),
    });
    const data = await response.json() as { candidates?: Array<{ content?: { parts?: Array<{ text?: string }> } }>; error?: { message?: string } };
    if (!response.ok) throw new Error(data.error?.message ?? 'Gemini request failed.');
    return String(data.candidates?.[0]?.content?.parts?.[0]?.text ?? '').trim();
  }
}

function parseJsonObject(text: string): Record<string, string> {
  const cleaned = text.replace(/```json|```/g, '').trim();
  const start = cleaned.indexOf('{');
  const end = cleaned.lastIndexOf('}');
  const json = start >= 0 && end > start ? cleaned.slice(start, end + 1) : cleaned;
  const value = JSON.parse(json) as Record<string, unknown>;
  return Object.fromEntries(Object.entries(value).map(([key, entry]) => [key, String(entry ?? '')]).filter(([, entry]) => entry));
}
