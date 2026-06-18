import type { MarketComparison, ProfitCalculation } from '@/shared/types';

export interface PricingInput {
  productCost: number;
  shippingCost: number;
  desiredProfit?: number;
  marketComparison?: MarketComparison;
  feePercent?: number;
  adPercent?: number;
}

function interpolate(value: number, min: number, max: number, outMin: number, outMax: number): number {
  if (max === min) return outMin;
  const ratio = Math.min(Math.max((value - min) / (max - min), 0), 1);
  return outMin + ratio * (outMax - outMin);
}

export function smartProfitTarget(landedCost: number, desiredProfit?: number): number {
  if (desiredProfit != null) return Math.max(0, desiredProfit);
  if (landedCost <= 10) return interpolate(landedCost, 0, 10, 5, 15);
  if (landedCost <= 25) return interpolate(landedCost, 10, 25, 8, 25);
  if (landedCost <= 50) return interpolate(landedCost, 25, 50, 15, 45);
  if (landedCost <= 100) return interpolate(landedCost, 50, 100, 25, 75);
  if (landedCost <= 200) return interpolate(landedCost, 100, 200, 40, 125);
  if (landedCost <= 450) return interpolate(landedCost, 200, 450, 75, 250);
  return Math.min(landedCost * 0.35, 450 + (landedCost - 450) * 0.15);
}

export function calculateProfit(input: PricingInput): ProfitCalculation {
  const feePercent = input.feePercent ?? 15;
  const adPercent = input.adPercent ?? 2;
  const marketplaceBufferPercent = feePercent + adPercent;
  const landedCost = roundMoney(input.productCost + input.shippingCost);
  const breakEvenPrice = roundMoney(landedCost / (1 - marketplaceBufferPercent / 100));
  const targetProfit = roundMoney(smartProfitTarget(landedCost, input.desiredProfit));
  let targetPrice = roundMoney(breakEvenPrice + targetProfit);
  const explanation = [
    `Landed cost = CJ product cost ${input.productCost.toFixed(2)} + CJ shipping ${input.shippingCost.toFixed(2)}.`,
    `Break-even = landed cost / (1 - ${(marketplaceBufferPercent / 100).toFixed(2)}) using ${feePercent}% eBay fee buffer + ${adPercent}% promoted ad buffer.`,
  ];

  let cappedByMarket = false;
  const market = input.marketComparison;
  if (market && market.confidenceScore < 0.72 && targetPrice > market.averageMarketPrice) {
    targetPrice = Math.max(breakEvenPrice, market.averageMarketPrice);
    cappedByMarket = true;
    explanation.push('Price capped at market average because comparable confidence is not high enough to exceed average.');
  } else if (market && targetPrice > market.highestReasonablePrice && market.confidenceScore < 0.9) {
    targetPrice = Math.max(breakEvenPrice, market.highestReasonablePrice);
    cappedByMarket = true;
    explanation.push('Price capped at highest reasonable comparable price because market confidence is below full-auto threshold.');
  }

  const estimatedFees = roundMoney(targetPrice * (marketplaceBufferPercent / 100));
  const estimatedProfit = roundMoney(targetPrice - estimatedFees - landedCost);
  const marginPercent = targetPrice > 0 ? roundMoney((estimatedProfit / targetPrice) * 100) : 0;

  if (targetPrice < breakEvenPrice) {
    targetPrice = breakEvenPrice;
    explanation.push('Price raised to break-even because the system never lists below break-even.');
  }

  explanation.push(`Target profit follows the gradual $0-$450+ ladder: ${targetProfit.toFixed(2)}.`);

  return {
    landedCost,
    marketplaceBufferPercent,
    breakEvenPrice,
    targetProfit,
    targetPrice,
    estimatedFees,
    estimatedProfit,
    marginPercent,
    cappedByMarket,
    explanation,
  };
}

function roundMoney(value: number): number {
  return Math.round((value + Number.EPSILON) * 100) / 100;
}
