import type { AutomationRules, ListingDraft, OptimizationRecommendation } from '@/shared/types';

export const defaultAutomationRules: AutomationRules = {
  feePercentage: 15,
  adPercentage: 2,
  minimumProfit: 0,
  maximumProfit: 450,
  priceDropSchedule: [
    { afterDays: 14, dropPercent: 3 },
    { afterDays: 30, dropPercent: 5 },
    { afterDays: 45, dropPercent: 6 },
  ],
  titleRewriteAfterDays: 30,
  imageChangeAfterDays: 30,
  endListingAfterDays: 60,
  autoListCategories: ['pet supplies', 'home improvement', 'phone accessories', 'kitchen gadgets', 'car accessories', 'beauty tools', 'office supplies'],
  automationMode: 'approval',
  dryRun: true,
};

export function canPublishDraft(draft: ListingDraft, rules: AutomationRules): { allowed: boolean; reason: string } {
  if (rules.dryRun) return { allowed: false, reason: 'Dry-run mode is enabled.' };
  if (rules.automationMode !== 'full_auto') return { allowed: false, reason: 'Approval or semi-auto mode requires manual approval before publishing.' };
  if (draft.duplicateDecision.status === 'blocked') return { allowed: false, reason: draft.duplicateDecision.explanation };
  if (draft.profit.estimatedProfit < rules.minimumProfit) return { allowed: false, reason: 'Estimated profit is below the configured minimum.' };
  return { allowed: true, reason: 'Draft satisfies full-auto publishing rules.' };
}

export function actionRequiresApproval(action: OptimizationRecommendation, rules: AutomationRules): boolean {
  if (rules.automationMode === 'approval') return true;
  if (rules.automationMode === 'semi_auto') return action.severity !== 'info';
  return action.requiresApproval;
}
