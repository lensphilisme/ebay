import { EbayClient } from '@/backend/integrations/ebay/client';

const itemIds = process.argv.slice(2).map((value) => value.trim()).filter(Boolean);

function firstTag(xml: string, tag: string): string {
  const match = xml.match(new RegExp(`<${tag}[^>]*>([\\s\\S]*?)</${tag}>`, 'i'));
  return match?.[1]?.replace(/<!\[CDATA\[|\]\]>/g, '').trim() ?? '';
}

async function main(): Promise<void> {
  if (itemIds.length === 0) throw new Error('Pass one or more eBay item IDs.');
  const ebay = new EbayClient() as unknown as { tradingRequest(callName: string, xml: string): Promise<string> };
  for (const itemId of itemIds) {
    const xml = `<?xml version="1.0" encoding="utf-8"?>
<GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <ItemID>${itemId}</ItemID>
  <DetailLevel>ReturnAll</DetailLevel>
</GetItemRequest>`;
    const response = await ebay.tradingRequest('GetItem', xml);
    console.log(JSON.stringify({
      itemId,
      ack: firstTag(response, 'Ack'),
      title: firstTag(response, 'Title'),
      listingStatus: firstTag(response, 'ListingStatus'),
      startTime: firstTag(response, 'StartTime'),
      endTime: firstTag(response, 'EndTime'),
      viewUrl: firstTag(response, 'ViewItemURL'),
      longMessages: [...response.matchAll(/<LongMessage>([\s\S]*?)<\/LongMessage>/gi)].map((match) => match[1].trim()),
    }));
  }
}

void main().catch((error) => {
  console.error(error instanceof Error ? error.stack ?? error.message : error);
  process.exitCode = 1;
});
