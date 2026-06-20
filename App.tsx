import { StatusBar } from 'expo-status-bar';
import {
  AlertCircle,
  Bot,
  ChevronLeft,
  ChevronRight,
  CheckCircle2,
  ClipboardList,
  DollarSign,
  Download,
  Eye,
  ExternalLink,
  Gauge,
  Heart,
  Image as ImageIcon,
  Loader2,
  MousePointerClick,
  PackageSearch,
  RefreshCcw,
  Settings,
  ShieldCheck,
  SlidersHorizontal,
  Store,
  ShoppingCart,
  Sparkles,
  TrendingUp,
  UploadCloud,
  Wand2,
  X,
} from 'lucide-react-native';
import { useEffect, useMemo, useState } from 'react';
import { Image as RNImage, Linking, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View, useWindowDimensions } from 'react-native';

type ScreenKey = 'home' | 'search' | 'builder' | 'queue' | 'active' | 'optimizer' | 'rules' | 'settings';
type IconType = typeof Gauge;
type JsonValue = Record<string, unknown> | unknown[] | string | number | boolean | null;

interface CategoryOption {
  id: string;
  name: string;
  path?: string;
  first?: string;
  second?: string;
}

interface AppMessage {
  kind: 'idle' | 'loading' | 'success' | 'error';
  text: string;
}

interface CjProductCard {
  id: string;
  title: string;
  sku?: string;
  image?: string;
  sellPrice?: string;
  nowPrice?: string;
  listedNum?: number;
  inventory?: number;
  categoryId?: string;
  videos: string[];
  raw: Record<string, unknown>;
}

interface CjSearchView {
  products: CjProductCard[];
  pageSize: number;
  pageNumber: number;
  totalRecords: number;
  totalPages: number;
}

interface VariantSummary {
  id: string;
  sku?: string;
  name: string;
  price: number;
  inventory: number;
  weight?: number;
  image?: string;
  attributes: string;
}

interface ProfitPreview {
  landedCost: number;
  breakEvenPrice: number;
  targetProfit: number;
  targetPrice: number;
  estimatedFees: number;
  estimatedProfit: number;
  marginPercent: number;
}

interface FreightOption {
  name: string;
  price: number;
  aging?: string;
}

interface BulkQueueItem {
  id: string;
  productId: string;
  variantId?: string;
  categoryId?: string;
  title: string;
  sku?: string;
  image?: string;
  variant?: string;
  productCost: number;
  shippingCost: number;
  landedCost: number;
  ebayPrice: number;
  estimatedProfit: number;
  inventory?: number;
  weight?: number;
  raw?: Record<string, unknown>;
}

interface DraftResponse {
  draft?: Record<string, unknown>;
  publishGuard?: Record<string, unknown>;
}

interface MarketplaceExportResponse {
  filename: string;
  mimeType: string;
  content: string;
  encoding?: 'text' | 'base64';
  rows: number;
  warnings: string[];
}

type ManualEndFilter = 'no_views' | 'no_clicks' | 'no_sales' | 'optimizer_end';

const screens: Array<{ key: ScreenKey; label: string; icon: IconType }> = [
  { key: 'home', label: 'Home', icon: Gauge },
  { key: 'search', label: 'CJ Search', icon: PackageSearch },
  { key: 'builder', label: 'Builder', icon: Wand2 },
  { key: 'queue', label: 'Queue', icon: ClipboardList },
  { key: 'active', label: 'Listings', icon: Store },
  { key: 'optimizer', label: 'Optimize', icon: Bot },
  { key: 'rules', label: 'Rules', icon: SlidersHorizontal },
  { key: 'settings', label: 'Settings', icon: Settings },
];

const apiBase = (() => {
  if (typeof window === 'undefined') return 'http://localhost:8787';
  const { protocol, hostname, port } = window.location;
  if (port === '8081') return `${protocol}//${hostname}:8787`;
  return '';
})();

async function api<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(`${apiBase}/api${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      ...(options.headers ?? {}),
    },
  });
  const data = (await response.json()) as T;
  if (!response.ok) {
    const message = typeof data === 'object' && data && 'error' in data ? String((data as { error: unknown }).error) : `Request failed: ${response.status}`;
    throw new Error(message);
  }
  return data;
}

function readStorage<T>(key: string, fallback: T): T {
  if (typeof window === 'undefined' || !window.localStorage) return fallback;
  try {
    const value = window.localStorage.getItem(key);
    return value ? (JSON.parse(value) as T) : fallback;
  } catch {
    return fallback;
  }
}

function writeStorage(key: string, value: unknown): void {
  if (typeof window === 'undefined' || !window.localStorage) return;
  try {
    window.localStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Browser storage can be unavailable in private modes; the app still works in memory.
  }
}

function downloadTextFile(file: MarketplaceExportResponse): void {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  const bytes = file.encoding === 'base64'
    ? Uint8Array.from(window.atob(file.content), (char) => char.charCodeAt(0))
    : file.content;
  const blob = new Blob([bytes], { type: file.mimeType || 'text/plain;charset=utf-8' });
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = file.filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}

function flattenCategories(value: unknown): CategoryOption[] {
  const found: CategoryOption[] = [];
  if (Array.isArray(value)) {
    value.forEach((firstNode) => {
      if (!firstNode || typeof firstNode !== 'object') return;
      const first = firstNode as Record<string, unknown>;
      const firstName = String(first.categoryFirstName ?? first.name ?? '');
      const secondList = Array.isArray(first.categoryFirstList) ? first.categoryFirstList : [];
      secondList.forEach((secondNode) => {
        if (!secondNode || typeof secondNode !== 'object') return;
        const second = secondNode as Record<string, unknown>;
        const secondName = String(second.categorySecondName ?? second.name ?? '');
        const thirdList = Array.isArray(second.categorySecondList) ? second.categorySecondList : [];
        thirdList.forEach((thirdNode) => {
          if (!thirdNode || typeof thirdNode !== 'object') return;
          const third = thirdNode as Record<string, unknown>;
          const id = third.categoryId ?? third.id;
          const name = third.categoryName ?? third.name;
          if (id != null && name != null) {
            found.push({ id: String(id), name: String(name), first: firstName, second: secondName, path: [firstName, secondName, String(name)].filter(Boolean).join(' > ') });
          }
        });
      });
    });
    if (found.length > 0) return found.sort((a, b) => (a.path ?? a.name).localeCompare(b.path ?? b.name));
  }
  const visit = (node: unknown) => {
    if (Array.isArray(node)) {
      node.forEach(visit);
      return;
    }
    if (!node || typeof node !== 'object') return;
    const item = node as Record<string, unknown>;
    const id = item.categoryId ?? item.id ?? item.cid;
    const name = item.categoryNameEn ?? item.categoryName ?? item.nameEn ?? item.name;
    if (id != null && name != null) {
      found.push({ id: String(id), name: String(name), path: String(name) });
    }
    Object.values(item).forEach(visit);
  };
  visit(value);
  return found.filter((category, index, all) => all.findIndex((entry) => entry.id === category.id) === index).sort((a, b) => (a.path ?? a.name).localeCompare(b.path ?? b.name));
}

function summarizeProducts(value: unknown): Array<Record<string, unknown>> {
  const products: Array<Record<string, unknown>> = [];
  const visit = (node: unknown) => {
    if (Array.isArray(node)) {
      node.forEach(visit);
      return;
    }
    if (!node || typeof node !== 'object') return;
    const item = node as Record<string, unknown>;
    if (item.id || item.pid || item.productId) {
      const title = item.nameEn ?? item.productNameEn ?? item.productName ?? item.title;
      if (title) products.push(item);
    }
    Object.values(item).forEach(visit);
  };
  visit(value);
  return products;
}

function cjSearchView(value: unknown): CjSearchView {
  const root = value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
  const products = summarizeProducts(value).map((product) => ({
    id: String(product.id ?? product.pid ?? product.productId ?? ''),
    title: String(product.nameEn ?? product.productNameEn ?? product.productName ?? product.title ?? 'Untitled CJ product'),
    sku: product.sku ? String(product.sku) : undefined,
    image: product.bigImage ? String(product.bigImage) : Array.isArray(product.productImageSet) ? String(product.productImageSet[0] ?? '') : undefined,
    sellPrice: product.sellPrice ? String(product.sellPrice) : undefined,
    nowPrice: product.nowPrice ? String(product.nowPrice) : undefined,
    listedNum: Number(product.listedNum ?? 0),
    inventory: Number(product.warehouseInventoryNum ?? product.totalVerifiedInventory ?? 0),
    categoryId: product.categoryId ? String(product.categoryId) : undefined,
    videos: Array.isArray(product.videoList) ? product.videoList.map(String) : [],
    raw: product,
  }));

  return {
    products,
    pageSize: Number(root.pageSize ?? 20),
    pageNumber: Number(root.pageNumber ?? 1),
    totalRecords: Number(root.totalRecords ?? products.length),
    totalPages: Number(root.totalPages ?? 1),
  };
}

function extractDetailImages(value: JsonValue): string[] {
  const urls = new Set<string>();
  const addUrlsFromString = (text: string) => {
    const matches = text.match(/https?:\/\/[^"'\s<>]+?\.(?:jpg|jpeg|png|webp)(?:\?[^"'\s<>]*)?/gi) ?? [];
    matches.forEach((url) => urls.add(url));
  };
  const visit = (node: unknown) => {
    if (Array.isArray(node)) return node.forEach(visit);
    if (typeof node === 'string') return addUrlsFromString(node);
    if (!node || typeof node !== 'object') return;
    for (const [key, entry] of Object.entries(node as Record<string, unknown>)) {
      if (typeof entry === 'string' && (key.toLowerCase().includes('image') || /\.(jpg|jpeg|png|webp)(\?|$)/i.test(entry))) {
        if (entry.startsWith('http')) urls.add(entry);
        addUrlsFromString(entry);
      } else {
        visit(entry);
      }
    }
  };
  visit(value);
  return [...urls].slice(0, 20);
}

function textFromHtml(html: string): string {
  return html
    .replace(/<style[\s\S]*?<\/style>/gi, '')
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|li|tr|h[1-6])>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;/gi, "'")
    .replace(/\s+\n/g, '\n')
    .replace(/\n\s+/g, '\n')
    .replace(/[ \t]{2,}/g, ' ')
    .trim();
}

function numberFrom(value: unknown, fallback = 0): number {
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  if (typeof value === 'string') {
    const first = value.match(/\d+(?:\.\d+)?/);
    if (first) return Number(first[0]);
  }
  return fallback;
}

function optionalNumberFrom(value: unknown): number | undefined {
  const number = numberFrom(value, Number.NaN);
  return Number.isFinite(number) ? number : undefined;
}

function extractVariants(value: JsonValue): VariantSummary[] {
  const root = value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
  const candidates = [root.variants, root.variantList, root.productVariants, root.skus].find(Array.isArray) as Array<Record<string, unknown>> | undefined;
  return (candidates ?? []).slice(0, 80).map((variant, index) => {
    const attrs = variant.variantKeyEn ?? variant.variantKey ?? variant.variantProperty ?? variant.productKeyEn ?? '';
    return {
      id: String(variant.vid ?? variant.id ?? variant.variantId ?? variant.sku ?? index),
      sku: variant.variantSku ? String(variant.variantSku) : variant.sku ? String(variant.sku) : undefined,
      name: String(variant.variantNameEn ?? variant.nameEn ?? variant.variantName ?? variant.sku ?? `Variant ${index + 1}`),
      price: numberFrom(variant.variantSellPrice ?? variant.sellPrice ?? variant.price),
      inventory: numberFrom(variant.inventoryNum ?? variant.inventory ?? variant.stock ?? variant.quantity),
      weight: optionalNumberFrom(variant.variantWeight ?? variant.weight),
      image: variant.variantImage ? String(variant.variantImage) : undefined,
      attributes: Array.isArray(attrs) ? attrs.map(String).join(', ') : String(attrs || ''),
    };
  });
}

function interpolate(value: number, min: number, max: number, outMin: number, outMax: number): number {
  if (max === min) return outMin;
  const ratio = Math.min(Math.max((value - min) / (max - min), 0), 1);
  return outMin + ratio * (outMax - outMin);
}

function smartProfitTarget(landedCost: number): number {
  if (landedCost <= 10) return interpolate(landedCost, 0, 10, 5, 15);
  if (landedCost <= 25) return interpolate(landedCost, 10, 25, 8, 25);
  if (landedCost <= 50) return interpolate(landedCost, 25, 50, 15, 45);
  if (landedCost <= 100) return interpolate(landedCost, 50, 100, 25, 75);
  if (landedCost <= 200) return interpolate(landedCost, 100, 200, 40, 125);
  if (landedCost <= 450) return interpolate(landedCost, 200, 450, 75, 250);
  return Math.min(landedCost * 0.35, 450 + (landedCost - 450) * 0.15);
}

function calculateProfitPreview(productCost: number, shippingCost: number, marketCap?: number): ProfitPreview {
  const marketplaceBuffer = 0.17;
  const landedCost = roundMoney(productCost + shippingCost);
  const breakEvenPrice = roundMoney(landedCost / (1 - marketplaceBuffer));
  const targetProfit = roundMoney(smartProfitTarget(landedCost));
  const uncappedTarget = roundMoney(breakEvenPrice + targetProfit);
  const targetPrice = marketCap && marketCap > breakEvenPrice ? Math.min(uncappedTarget, marketCap) : uncappedTarget;
  const estimatedFees = roundMoney(targetPrice * marketplaceBuffer);
  const estimatedProfit = roundMoney(targetPrice - estimatedFees - landedCost);
  const marginPercent = targetPrice > 0 ? roundMoney((estimatedProfit / targetPrice) * 100) : 0;
  return { landedCost, breakEvenPrice, targetProfit, targetPrice, estimatedFees, estimatedProfit, marginPercent };
}

function roundMoney(value: number): number {
  return Math.round((value + Number.EPSILON) * 100) / 100;
}

function profitLadderRows(baseCost: number, shippingCost: number): ProfitPreview[] {
  const anchors = [0, 5, 10, 12, 15, 17, 20, 25, 35, 50, 75, 100, 150, 200, 300, 400, 450, 600];
  const costs = Array.from(new Set([baseCost, ...anchors])).filter((cost) => cost >= 0).sort((a, b) => a - b);
  return costs.map((cost) => calculateProfitPreview(cost, cost === baseCost ? shippingCost : 0));
}

function extractFreightOptions(value: JsonValue): FreightOption[] {
  const root = value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
  const rows = Array.isArray(root) ? root : Array.isArray(root.value) ? root.value : Array.isArray(root.data) ? root.data : [];
  return (rows as Array<Record<string, unknown>>)
    .map((row) => ({
      name: String(row.logisticName ?? row.name ?? row.channelName ?? 'CJ logistics'),
      price: numberFrom(row.logisticPrice ?? row.totalPostageFee ?? row.postage ?? row.price),
      aging: row.logisticAging ? String(row.logisticAging) : undefined,
    }))
    .filter((row) => row.price > 0)
    .sort((a, b) => a.price - b.price);
}

function extractVideoSources(detail: JsonValue, fallbackIds: string[]): string[] {
  const sources = new Set<string>();
  const visit = (node: unknown) => {
    if (Array.isArray(node)) return node.forEach(visit);
    if (typeof node === 'string') {
      if (/^https?:\/\/.+\.(mp4|webm|mov|m3u8)(\?|$)/i.test(node)) sources.add(node);
      const urls = node.match(/https?:\/\/[^"'\s<>]+?\.(?:mp4|webm|mov|m3u8)(?:\?[^"'\s<>]*)?/gi) ?? [];
      urls.forEach((url) => sources.add(url));
      return;
    }
    if (!node || typeof node !== 'object') return;
    Object.values(node as Record<string, unknown>).forEach(visit);
  };
  visit(detail);
  fallbackIds.filter((value) => /^https?:\/\//i.test(value)).forEach((url) => sources.add(url));
  return [...sources].slice(0, 8);
}

export default function App() {
  const [active, setActive] = useState<ScreenKey>(() => readStorage<ScreenKey>('cj-ebay-active-screen', 'home'));
  const [message, setMessage] = useState<AppMessage>({ kind: 'idle', text: 'Ready.' });
  const [health, setHealth] = useState<JsonValue>(null);
  const [dashboard, setDashboard] = useState<JsonValue>(null);
  const [settingsStatus, setSettingsStatus] = useState<JsonValue>(null);
  const [rules, setRules] = useState<JsonValue>(null);
  const [logs, setLogs] = useState<JsonValue>(null);
  const [categories, setCategories] = useState<CategoryOption[]>([]);
  const [warehouses, setWarehouses] = useState<JsonValue>(null);
  const [searchResult, setSearchResult] = useState<JsonValue>(() => readStorage<JsonValue>('cj-ebay-search-result', null));
  const [optimizerResult, setOptimizerResult] = useState<JsonValue>(null);
  const [bulkQueue, setBulkQueue] = useState<BulkQueueItem[]>(() => readStorage<BulkQueueItem[]>('cj-ebay-bulk-queue', []));
  const { width } = useWindowDimensions();
  const isWide = width >= 940;
  const ActiveIcon = screens.find((screen) => screen.key === active)?.icon ?? Gauge;

  const run = async <T,>(loadingText: string, successText: string, work: () => Promise<T>, onSuccess?: (data: T) => void) => {
    setMessage({ kind: 'loading', text: loadingText });
    try {
      const data = await work();
      onSuccess?.(data);
      setMessage({ kind: 'success', text: successText });
      return data;
    } catch (error) {
      setMessage({ kind: 'error', text: error instanceof Error ? error.message : 'Unknown error' });
      return undefined;
    }
  };

  const loadHealth = () =>
    run('Checking eBay and CJ credentials...', 'Integration check finished.', () => api<JsonValue>('/integrations/health'), setHealth);

  const loadDashboard = () =>
    run('Loading seller dashboard data...', 'Dashboard refreshed.', () => api<JsonValue>('/dashboard'), setDashboard);

  const loadSettings = () =>
    run('Reading credential status from environment...', 'Settings status loaded.', () => api<JsonValue>('/settings/status'), setSettingsStatus);

  const loadRules = () => run('Loading optimization strategy...', 'Rules loaded.', () => api<JsonValue>('/rules'), setRules);

  const loadLogs = () => run('Loading audit and job logs...', 'Logs loaded.', () => api<JsonValue>('/logs'), setLogs);

  useEffect(() => {
    void loadSettings();
    void loadDashboard();
  }, []);

  useEffect(() => writeStorage('cj-ebay-active-screen', active), [active]);
  useEffect(() => writeStorage('cj-ebay-bulk-queue', bulkQueue), [bulkQueue]);
  useEffect(() => writeStorage('cj-ebay-search-result', searchResult), [searchResult]);

  const screenBody = useMemo(() => {
    switch (active) {
      case 'search':
        return <SearchScreen run={run} categories={categories} setCategories={setCategories} warehouses={warehouses} setWarehouses={setWarehouses} result={searchResult} setResult={setSearchResult} addToQueue={(item) => setBulkQueue((queue) => queue.some((entry) => entry.id === item.id) ? queue : [...queue, item])} />;
      case 'builder':
        return <BuilderScreen />;
      case 'queue':
        return <QueueScreen items={bulkQueue} run={run} />;
      case 'active':
        return <ActiveListingsScreen run={run} />;
      case 'optimizer':
        return <OptimizerScreen run={run} result={optimizerResult} setResult={setOptimizerResult} />;
      case 'rules':
        return <RulesScreen rules={rules} loadRules={loadRules} />;
      case 'settings':
        return <SettingsScreen status={settingsStatus} health={health} logs={logs} loadHealth={loadHealth} loadSettings={loadSettings} loadLogs={loadLogs} run={run} />;
      default:
        return <HomeScreen dashboard={dashboard} health={health} settingsStatus={settingsStatus} loadDashboard={loadDashboard} loadHealth={loadHealth} loadRules={loadRules} />;
    }
  }, [active, bulkQueue, categories, dashboard, health, logs, optimizerResult, rules, searchResult, settingsStatus, warehouses]);

  return (
    <View style={styles.app}>
      <StatusBar style="dark" />
      <View style={[styles.shell, isWide && styles.shellWide]}>
        <View style={[styles.sidebar, !isWide && styles.sidebarMobile]}>
          <View style={styles.brandRow}>
            <ShieldCheck color="#0f766e" size={26} />
            <View>
              <Text style={styles.brand}>CJ to eBay</Text>
              <Text style={styles.brandSub}>Private operator console</Text>
            </View>
          </View>
          <ScrollView horizontal={!isWide} showsHorizontalScrollIndicator={false} contentContainerStyle={!isWide && styles.mobileNavContent}>
            {screens.map((screen) => {
              const Icon = screen.icon;
              const selected = active === screen.key;
              return (
                <Pressable key={screen.key} onPress={() => setActive(screen.key)} style={[styles.navItem, selected && styles.navItemActive]}>
                  <Icon size={18} color={selected ? '#0f766e' : '#52606d'} />
                  <Text style={[styles.navText, selected && styles.navTextActive]}>{screen.label}</Text>
                </Pressable>
              );
            })}
          </ScrollView>
        </View>

        <ScrollView style={styles.content} contentContainerStyle={styles.contentInner}>
          <View style={styles.header}>
            <View style={styles.headerText}>
              <Text style={styles.eyebrow}>Approval mode first</Text>
              <Text style={styles.title}>Private listing automation</Text>
              <Text style={styles.headerCopy}>One TypeScript app protects secrets server-side while the dashboard controls CJ search, eBay OAuth, listing strategy, and optimization decisions.</Text>
            </View>
            <View style={styles.headerBadge}>
              <ActiveIcon color="#0f766e" size={18} />
              <Text style={styles.headerBadgeText}>{screens.find((screen) => screen.key === active)?.label}</Text>
            </View>
          </View>
          <MessageBar message={message} />
          {screenBody}
        </ScrollView>
      </View>
    </View>
  );
}

function HomeScreen({ dashboard, health, settingsStatus, loadDashboard, loadHealth, loadRules }: { dashboard: JsonValue; health: JsonValue; settingsStatus: JsonValue; loadDashboard: () => void; loadHealth: () => void; loadRules: () => void }) {
  const dash = dashboard && typeof dashboard === 'object' ? (dashboard as Record<string, unknown>) : {};
  const ebay = dash.ebay && typeof dash.ebay === 'object' ? (dash.ebay as Record<string, unknown>) : {};
  const automation = dash.automation && typeof dash.automation === 'object' ? (dash.automation as Record<string, unknown>) : {};
  const warnings = Array.isArray(ebay.warnings) ? ebay.warnings.map(String) : [];

  return (
    <View>
      <View style={styles.actions}>
        <Button label="Refresh Dashboard" icon={Gauge} onPress={loadDashboard} />
        <Button label="Check Integrations" icon={RefreshCcw} onPress={loadHealth} />
        <Button label="Load Strategy Rules" icon={SlidersHorizontal} onPress={loadRules} secondary />
      </View>
      <View style={styles.metricGrid}>
        <MetricCard label="eBay active listings" value={String(ebay.activeListings ?? 0)} detail="From eBay Inventory API" />
        <MetricCard label="Optimizer actions" value={String(ebay.optimizationRecommendations ?? 0)} detail="Generated from listing metrics" />
        <MetricCard label="Automation mode" value={String(automation.mode ?? 'approval')} detail={automation.dryRun ? 'Dry-run enabled' : 'Live actions guarded'} />
        <MetricCard label="Integration status" value={health ? 'Checked' : 'Pending'} detail="CJ and eBay use .env credentials" />
      </View>
      {warnings.length > 0 && <Notice title="Dashboard warnings" items={warnings} />}
      <View style={styles.healthGrid}>
        <StatusCard title="eBay" detail="Reads seller privileges, listings, offers, and analytics through the TypeScript server." data={health} />
        <StatusCard title="CJ" detail="Categories, warehouses, products, product detail, variants, inventory, and freight are API-backed." data={settingsStatus} />
      </View>
      <Panel title="Real Work Flow">
        <Step done label="Fetch CJ categories and warehouses from CJ API; pick values instead of memorizing IDs." />
        <Step done label="Search CJ products with filters, then calculate freight and landed cost." />
        <Step done label="Research eBay comparables, reject weak matches, and price above break-even." />
        <Step done label="Send weak listings to an approval queue with a clear marketing action and reason." />
      </Panel>
    </View>
  );
}

function SearchScreen(props: {
  run: <T>(loadingText: string, successText: string, work: () => Promise<T>, onSuccess?: (data: T) => void) => Promise<T | undefined>;
  categories: CategoryOption[];
  setCategories: (categories: CategoryOption[]) => void;
  warehouses: JsonValue;
  setWarehouses: (value: JsonValue) => void;
  result: JsonValue;
  setResult: (value: JsonValue) => void;
  addToQueue: (item: BulkQueueItem) => void;
}) {
  const [keyword, setKeyword] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<CategoryOption | null>(null);
  const [countryCode, setCountryCode] = useState('US');
  const [minInventory, setMinInventory] = useState('5');
  const [maxInventory, setMaxInventory] = useState('');
  const [minPrice, setMinPrice] = useState('');
  const [maxPrice, setMaxPrice] = useState('');
  const [productFlag, setProductFlag] = useState('');
  const [orderBy, setOrderBy] = useState('0');
  const [sort, setSort] = useState<'asc' | 'desc'>('desc');
  const [pageSize, setPageSize] = useState(30);
  const [pageNumber, setPageNumber] = useState(1);
  const [selectedProduct, setSelectedProduct] = useState<CjProductCard | null>(null);
  const [productDetail, setProductDetail] = useState<JsonValue>(null);
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const view = cjSearchView(props.result);
  const products = view.products;

  const loadCategories = () =>
    props.run('Fetching CJ category tree...', 'CJ categories loaded.', () => api<JsonValue>('/cj/categories'), (data) => props.setCategories(flattenCategories(data)));
  const loadWarehouses = () =>
    props.run('Fetching CJ warehouses...', 'CJ warehouses loaded.', () => api<JsonValue>('/cj/warehouses'), props.setWarehouses);
  useEffect(() => {
    if (props.categories.length === 0) void loadCategories();
  }, []);

  const search = (nextPage = pageNumber, nextPageSize = pageSize) =>
    props.run(
      'Searching CJ products...',
      'CJ search finished.',
      () =>
        api<JsonValue>('/cj/search', {
          method: 'POST',
          body: JSON.stringify({
            keyword,
            categoryId: selectedCategory?.id,
            countryCode,
            isWarehouse: Boolean(countryCode),
            minInventory: Number(minInventory || 0),
            maxInventory: maxInventory ? Number(maxInventory) : undefined,
            minPrice: minPrice ? Number(minPrice) : undefined,
            maxPrice: maxPrice ? Number(maxPrice) : undefined,
            productFlag: productFlag ? Number(productFlag) : undefined,
            orderBy: Number(orderBy),
            sort,
            features: ['enable_video', 'enable_category'],
            zonePlatform: 'ebay',
            pageNum: nextPage,
            pageSize: nextPageSize,
          }),
        }),
      (data) => {
        setPageNumber(nextPage);
        setPageSize(nextPageSize);
        props.setResult(data);
      }
    );

  const openProduct = (product: CjProductCard) => {
    setSelectedProduct(product);
    setProductDetail(null);
    void props.run(
      'Loading CJ product detail...',
      'Product detail loaded.',
      () => api<JsonValue>(`/cj/product?pid=${encodeURIComponent(product.id)}&countryCode=${encodeURIComponent(countryCode)}`),
      setProductDetail
    );
  };

  const makeQueueItem = (product: CjProductCard): BulkQueueItem => {
    const productCost = numberFrom(product.nowPrice ?? product.sellPrice);
    const profit = calculateProfitPreview(productCost, 0);
    return {
      id: `${product.id}:product`,
      productId: product.id,
      categoryId: product.categoryId,
      title: product.title.slice(0, 80),
      sku: product.sku,
      image: product.image,
      productCost,
      shippingCost: 0,
      landedCost: profit.landedCost,
      ebayPrice: profit.targetPrice,
      estimatedProfit: profit.estimatedProfit,
      inventory: product.inventory,
      raw: product.raw,
    };
  };

  const addSelectedToQueue = () => {
    const selected = products.filter((product) => selectedIds.includes(product.id));
    selected.forEach((product) => props.addToQueue(makeQueueItem(product)));
    if (selected.length > 0) setSelectedIds([]);
  };
  const exportSelected = (marketplace: 'ebay' | 'facebook' | 'tiktok', format: 'csv' | 'xls' | 'xlsx' = 'csv') => {
    const selected = products.filter((product) => selectedIds.includes(product.id));
    return props.run(
      `Building ${marketplace} marketplace export...`,
      `${marketplace.toUpperCase()} export ready.`,
      () => api<MarketplaceExportResponse>('/cj/export-marketplace', {
        method: 'POST',
        body: JSON.stringify({
          marketplace,
          format,
          countryCode,
          items: selected.map(makeQueueItem),
        }),
      }),
      (file) => {
        downloadTextFile(file);
        if (file.warnings?.length) console.log(`${marketplace} export warnings`, file.warnings);
      }
    );
  };

  return (
    <View>
      <Panel title="CJ Product Search">
        <View style={styles.formGrid}>
          <Input label="Keyword" value={keyword} onChangeText={setKeyword} placeholder="phone stand, dog bed, kitchen organizer" />
          <Input label="Warehouse country" value={countryCode} onChangeText={setCountryCode} placeholder="US, CN, GB" />
          <Input label="Minimum inventory" value={minInventory} onChangeText={setMinInventory} placeholder="5" keyboardType="numeric" />
          <Input label="Maximum inventory" value={maxInventory} onChangeText={setMaxInventory} placeholder="optional" keyboardType="numeric" />
          <Input label="Min price" value={minPrice} onChangeText={setMinPrice} placeholder="0" keyboardType="numeric" />
          <Input label="Max price" value={maxPrice} onChangeText={setMaxPrice} placeholder="50" keyboardType="numeric" />
        </View>
        <View style={styles.filterGrid}>
          <SelectPills label="Product flag" options={[['', 'Any'], ['0', 'Trending'], ['1', 'New'], ['2', 'Has video'], ['3', 'Slow moving']]} selected={productFlag} onSelect={setProductFlag} />
          <SelectPills label="Sort by" options={[['0', 'Best'], ['1', 'Listed'], ['2', 'Price'], ['3', 'Newest'], ['4', 'Inventory']]} selected={orderBy} onSelect={setOrderBy} />
          <SelectPills label="Direction" options={[['desc', 'High first'], ['asc', 'Low first']]} selected={sort} onSelect={(value) => setSort(value as 'asc' | 'desc')} />
        </View>
        <View style={styles.actions}>
          <Button label="Load Warehouses" icon={Store} onPress={loadWarehouses} secondary />
          <Button label="Search Products" icon={PackageSearch} onPress={() => search(1, pageSize)} />
          <Button label={`Add Selected (${selectedIds.length})`} icon={ClipboardList} onPress={addSelectedToQueue} secondary />
          <Button label="eBay CSV" icon={Download} onPress={() => exportSelected('ebay')} secondary disabled={selectedIds.length === 0} />
          <Button label="Meta Catalog CSV" icon={Download} onPress={() => exportSelected('facebook')} secondary disabled={selectedIds.length === 0} />
          <Button label="Facebook XLSX" icon={Download} onPress={() => exportSelected('facebook', 'xlsx')} secondary disabled={selectedIds.length === 0} />
          <Button label="TikTok XLSX" icon={Download} onPress={() => exportSelected('tiktok', 'xlsx')} secondary disabled={selectedIds.length === 0} />
        </View>
        <CategoryDropdown title="CJ category" options={props.categories} selected={selectedCategory} onSelect={setSelectedCategory} />
        <Segmented label="Products per page" values={[30, 40, 50, 100]} selected={pageSize} onSelect={(size) => search(1, size)} />
        <View style={styles.filterSummary}>
          {[keyword && `Keyword: ${keyword}`, selectedCategory && `Category: ${selectedCategory.path ?? selectedCategory.name}`, countryCode && `Warehouse: ${countryCode}`, minInventory && `Stock >= ${minInventory}`, maxInventory && `Stock <= ${maxInventory}`, minPrice && `Price >= $${minPrice}`, maxPrice && `Price <= $${maxPrice}`, productFlag && `Flag ${productFlag}`].filter(Boolean).map((item) => <Badge key={String(item)} text={String(item)} />)}
        </View>
      </Panel>
      {products.length > 0 ? (
        <>
          <Pagination current={view.pageNumber || pageNumber} total={view.totalPages || 1} onPage={(page) => search(page, pageSize)} />
          <Text style={styles.resultSummary}>{view.totalRecords.toLocaleString()} products found</Text>
          <View style={styles.resultGrid}>
            {products.map((product) => (
              <ProductCard
                key={product.id}
                product={product}
                selected={selectedIds.includes(product.id)}
                onToggleSelect={() => setSelectedIds((ids) => ids.includes(product.id) ? ids.filter((id) => id !== product.id) : [...ids, product.id])}
                onPress={() => openProduct(product)}
              />
            ))}
          </View>
          <Pagination current={view.pageNumber || pageNumber} total={view.totalPages || 1} onPage={(page) => search(page, pageSize)} />
        </>
      ) : (
        <EmptyState title="No CJ results loaded" detail="Load categories, pick a category if needed, then run a real CJ search." />
      )}
      <ProductDetailModal product={selectedProduct} detail={productDetail} countryCode={countryCode} onClose={() => setSelectedProduct(null)} addToQueue={props.addToQueue} />
    </View>
  );
}

function BuilderScreen() {
  return (
    <TwoColumn
      left={
        <Panel title="What Builder Means">
          <Text style={styles.bodyText}>This screen is only the approval-mode draft sandbox. It explains the data the private automation uses before it builds a listing. You do not need to paste product text here for normal CJ to eBay listing.</Text>
          {['CJ product detail, variants, images, and inventory are fetched by API.', 'eBay category and item-specific requirements are fetched from eBay before XML is created.', 'AI fills real eBay item specifics when it has factual product data, then rules repair missing required fields.', 'Duplicate and margin checks stay in approval mode before anything live is published.'].map((item) => (
            <ChecklistItem key={item} text={item} />
          ))}
        </Panel>
      }
      right={
        <Panel title="What You Use Day To Day">
          <Text style={styles.bodyText}>Use CJ Search and Queue for products, Listings for manual control, and Optimize for AI/rule recommendations. Draft preview text appears only when you intentionally create a review draft.</Text>
          {['Search imports products from CJ and keeps product/variant IDs attached.', 'Queue can create approval drafts when you want to review price, title, and duplicate risk.', 'Listings shows live eBay data and lets you manually select weak listings to end.', 'Optimize can recommend improvements, but manual controls always stay available.'].map((item) => (
            <ChecklistItem key={item} text={item} />
          ))}
        </Panel>
      }
    />
  );
}

function QueueScreen({ items, run }: { items: BulkQueueItem[]; run: <T>(loadingText: string, successText: string, work: () => Promise<T>, onSuccess?: (data: T) => void) => Promise<T | undefined> }) {
  const [drafts, setDrafts] = useState<Record<string, DraftResponse>>({});
  const [listings, setListings] = useState<Record<string, JsonValue>>({});
  const [bulkListResult, setBulkListResult] = useState<JsonValue>(null);
  const createDraft = (item: BulkQueueItem) =>
    run(
      `Creating draft for ${item.title.slice(0, 42)}...`,
      'Listing draft created and checked for duplicate risk.',
      () => api<DraftResponse>('/drafts/from-queue', { method: 'POST', body: JSON.stringify({ item }) }),
      (data) => setDrafts((current) => ({ ...current, [item.id]: data }))
    );
  const listLive = (item: BulkQueueItem) =>
    run(
      `Listing ${item.title.slice(0, 42)} live on eBay...`,
      'eBay live listing request finished.',
      () => api<JsonValue>('/ebay/list/from-queue', { method: 'POST', body: JSON.stringify({ item, countryCode: 'US' }) }),
      (data) => setListings((current) => ({ ...current, [item.id]: data }))
    );
  const createAllDrafts = async () => {
    for (const item of items) {
      if (!drafts[item.id]) await createDraft(item);
    }
  };
  const listAllLive = () =>
    run(
      `Bulk listing ${items.length} queued CJ products on eBay...`,
      'Bulk eBay live listing run finished.',
      () => api<JsonValue>('/ebay/list/bulk-from-queue', { method: 'POST', body: JSON.stringify({ items, countryCode: 'US' }) }),
      setBulkListResult
    );
  if (items.length === 0) return <EmptyState title="Bulk Listing Queue" detail="Open a CJ product preview, calculate freight, then add it here for live eBay listing or review-draft creation." />;
  return (
    <Panel title="Bulk Listing Queue">
      <Text style={styles.bodyText}>Queue items can still create local review drafts, but the main action now publishes through the production CJ-to-eBay listing engine with eBay preflight first.</Text>
      <View style={styles.actions}>
        <Button label={`List All Live (${items.length})`} icon={UploadCloud} onPress={listAllLive} />
        <Button label={`Create Review Drafts (${items.length})`} icon={ClipboardList} onPress={createAllDrafts} secondary />
      </View>
      <ListingRunSummary data={bulkListResult} />
      <View style={styles.resultGrid}>
        {items.map((item) => (
          <View key={item.id} style={styles.productCard}>
            {item.image && <RNImage source={{ uri: item.image }} style={styles.queueImage} resizeMode="cover" />}
            <Text style={styles.cardTitle} numberOfLines={2}>{item.title}</Text>
            <Text style={styles.muted} numberOfLines={1}>{item.sku ?? item.variant ?? 'CJ product'}</Text>
            <View style={styles.miniGrid}>
              <MetricCard label="Landed" value={`$${item.landedCost.toFixed(2)}`} detail="CJ + shipping" />
              <MetricCard label="eBay price" value={`$${item.ebayPrice.toFixed(2)}`} detail="Before market cap" />
              <MetricCard label="Profit" value={`$${item.estimatedProfit.toFixed(2)}`} detail="After fee buffer" />
            </View>
            <View style={styles.actions}>
              <Button label="List Live" icon={UploadCloud} onPress={() => listLive(item)} />
              <Button label={drafts[item.id] ? 'Refresh Draft' : 'Review Draft'} icon={ClipboardList} onPress={() => createDraft(item)} secondary />
            </View>
            <ListingRunSummary data={listings[item.id]} compact />
            <DraftPreview response={drafts[item.id]} />
          </View>
        ))}
      </View>
    </Panel>
  );
}

function ListingRunSummary({ data, compact }: { data: JsonValue; compact?: boolean }) {
  if (!data || typeof data !== 'object') return null;
  const root = data as Record<string, unknown>;
  const results = Array.isArray(root.results) ? root.results as Array<Record<string, unknown>> : [root];
  const passed = Number(root.passed ?? results.filter((item) => item.status === 'passed').length);
  const failed = Number(root.failed ?? results.filter((item) => item.status === 'failed').length);
  const itemIds = results
    .map((item) => (item.ebayAttempt && typeof item.ebayAttempt === 'object' ? (item.ebayAttempt as Record<string, unknown>).itemId : undefined))
    .filter(Boolean)
    .map(String);
  const errors = results.flatMap((item) => Array.isArray(item.errors) ? item.errors.map(String) : []);
  return (
    <View style={compact ? styles.inlineSummary : styles.runSummary}>
      <View style={styles.cardStats}>
        <Badge text={`${passed} passed`} tone={failed === 0 ? 'green' : undefined} />
        <Badge text={`${failed} failed`} />
        {itemIds.slice(0, 3).map((id) => <Badge key={id} text={`eBay ${id}`} tone="green" />)}
      </View>
      {errors.length > 0 && <Text style={styles.badLine} numberOfLines={3}>{errors.slice(0, 3).join(' | ')}</Text>}
    </View>
  );
}

function DraftPreview({ response }: { response?: DraftResponse }) {
  if (!response?.draft) return null;
  const draft = response.draft;
  const duplicate = draft.duplicateDecision && typeof draft.duplicateDecision === 'object' ? draft.duplicateDecision as Record<string, unknown> : {};
  const profit = draft.profit && typeof draft.profit === 'object' ? draft.profit as Record<string, unknown> : {};
  const guard = (response.publishGuard ?? {}) as Record<string, unknown>;
  const actions = Array.isArray(draft.actionPreview) ? draft.actionPreview.map(String) : [];
  return (
    <View style={styles.draftPreview}>
      <View style={styles.cardStats}>
        <Badge text={`Draft $${Number(draft.price ?? 0).toFixed(2)}`} tone="green" />
        <Badge text={`Duplicate ${String(duplicate.status ?? 'unknown')}`} tone={duplicate.status === 'clear' ? 'green' : undefined} />
        <Badge text={String(guard.allowed ? 'Publish eligible' : 'Approval required')} />
      </View>
      <Text style={styles.statusText} numberOfLines={2}>{String(draft.title ?? 'Draft title')}</Text>
      <Text style={styles.muted}>Net profit ${Number(profit.estimatedProfit ?? 0).toFixed(2)} after fees. SKU {String(draft.sku ?? 'pending')}.</Text>
      {actions.length > 0 && <Notice title="Draft action preview" items={actions.slice(0, 3)} />}
    </View>
  );
}

function ActiveListingsScreen({ run }: { run: <T>(loadingText: string, successText: string, work: () => Promise<T>, onSuccess?: (data: T) => void) => Promise<T | undefined> }) {
  const [scan, setScan] = useState<JsonValue>(null);
  const [days, setDays] = useState('14');
  const [limit, setLimit] = useState('250');
  const [manualFilter, setManualFilter] = useState<ManualEndFilter>('no_views');
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [confirmEnd, setConfirmEnd] = useState('');
  const [endResult, setEndResult] = useState<JsonValue>(null);
  const load = () =>
    run(
      'Loading active eBay dashboard...',
      'eBay dashboard loaded.',
      () => api<JsonValue>(`/optimizer/scan?days=${encodeURIComponent(days || '14')}&limit=${encodeURIComponent(limit || '250')}`),
      setScan
    );
  useEffect(() => {
    void load();
  }, []);
  const root = scan && typeof scan === 'object' ? (scan as Record<string, unknown>) : {};
  const rows = Array.isArray(root.listings) ? (root.listings as Array<Record<string, unknown>>) : [];
  const traffic = Array.isArray(root.traffic) ? (root.traffic as Array<Record<string, unknown>>) : [];
  const recommendations = Array.isArray(root.recommendations) ? (root.recommendations as Array<Record<string, unknown>>) : [];
  const totalValue = rows.reduce((sum, listing) => sum + Number(listing.price ?? 0) * Number(listing.quantityAvailable ?? 0), 0);
  const selectableRows = rows.filter((listing) => listingMatchesManualFilter(listing, traffic, recommendations, manualFilter));
  const selectedRows = rows.filter((listing) => selectedIds.includes(String(listing.listingId)));
  const requiredConfirm = `END ${selectedIds.length}`;
  const selectMatching = () => setSelectedIds(selectableRows.map((listing) => String(listing.listingId)).filter(Boolean));
  const clearSelected = () => {
    setSelectedIds([]);
    setConfirmEnd('');
  };
  const toggleSelected = (listingId: string) =>
    setSelectedIds((ids) => ids.includes(listingId) ? ids.filter((id) => id !== listingId) : [...ids, listingId]);
  const endSelected = () =>
    run(
      `Ending ${selectedIds.length} selected eBay listings...`,
      'Manual bulk end request finished.',
      () => api<JsonValue>('/ebay/listings/end-bulk', {
        method: 'POST',
        body: JSON.stringify({
          listingIds: selectedIds,
          reason: 'NotAvailable',
          confirm: confirmEnd,
          note: `Manual dashboard bulk end: ${manualFilterLabel(manualFilter)} over ${days || '14'} days.`,
        }),
      }),
      (data) => {
        setEndResult(data);
        clearSelected();
        void load();
      }
    );
  return (
    <Panel title="Active eBay Listings">
      <Text style={styles.bodyText}>Manual ending is separate from the AI optimizer. Load a traffic window, select weak listings by data, then type the exact confirmation phrase before anything is ended.</Text>
      <View style={styles.formGrid}>
        <Input label="Traffic window days" value={days} onChangeText={setDays} keyboardType="numeric" />
        <Input label="Listing scan limit" value={limit} onChangeText={setLimit} keyboardType="numeric" />
        <Input label={`Confirm bulk end (${requiredConfirm})`} value={confirmEnd} onChangeText={setConfirmEnd} placeholder={requiredConfirm} />
      </View>
      <View style={styles.actions}>
        <Button label="Refresh Listings" icon={RefreshCcw} onPress={load} secondary />
        <Button label="No Views" icon={Gauge} secondary={manualFilter !== 'no_views'} onPress={() => setManualFilter('no_views')} />
        <Button label="No Clicks" icon={Gauge} secondary={manualFilter !== 'no_clicks'} onPress={() => setManualFilter('no_clicks')} />
        <Button label="No Sales" icon={Gauge} secondary={manualFilter !== 'no_sales'} onPress={() => setManualFilter('no_sales')} />
        <Button label="End/Relist Rec" icon={Bot} secondary={manualFilter !== 'optimizer_end'} onPress={() => setManualFilter('optimizer_end')} />
        <Button label={`Select Matches (${selectableRows.length})`} icon={CheckCircle2} onPress={selectMatching} secondary />
        <Button label="Clear Selection" icon={X} onPress={clearSelected} secondary />
        <Button label={`End Selected (${selectedIds.length})`} icon={X} onPress={endSelected} danger disabled={selectedIds.length === 0 || confirmEnd !== requiredConfirm} />
      </View>
      <View style={styles.miniGrid}>
        <MetricCard label="Active listings" value={String(rows.length)} detail="Inventory + Trading API fallback" />
        <MetricCard label="Inventory value" value={`$${totalValue.toFixed(2)}`} detail="Price x available quantity" />
        <MetricCard label="Traffic rows" value={String(traffic.length)} detail="eBay Analytics listing metrics" />
        <MetricCard label="Needs action" value={String(recommendations.filter((item) => String((item.recommendation as Record<string, unknown> | undefined)?.action ?? 'none') !== 'none').length)} detail="Rule/AI optimizer candidates" />
        <MetricCard label="Filter matches" value={String(selectableRows.length)} detail={manualFilterLabel(manualFilter)} />
        <MetricCard label="Selected" value={String(selectedRows.length)} detail="Manual bulk-end queue" />
      </View>
      {endResult && typeof endResult === 'object' && (
        <Notice
          title="Last manual end result"
          items={[
            `Ended ${String((endResult as Record<string, unknown>).ended ?? 0)} listing(s).`,
            `Failed ${String((endResult as Record<string, unknown>).failed ?? 0)} listing(s).`,
          ]}
        />
      )}
      {rows.length === 0 ? (
        <Notice title="No listings loaded" items={['The app checks Inventory API first and falls back to Trading API for legacy listings created by the old importer.']} />
      ) : (
        <View style={styles.resultGrid}>
          {rows.map((listing) => {
            const metric = traffic.find((row) => String(row.listingId) === String(listing.listingId));
            const recommendation = recommendations.find((item) => String((item.listing as Record<string, unknown> | undefined)?.listingId) === String(listing.listingId));
            const rec = recommendation?.recommendation as Record<string, unknown> | undefined;
            const imageUrl = typeof listing.imageUrl === 'string' ? listing.imageUrl : undefined;
            const listingId = String(listing.listingId);
            const hasTraffic = Boolean(metric);
            const views = hasTraffic ? Number(metric?.views ?? 0) : undefined;
            const clicks = hasTraffic ? listingClicks(metric) : undefined;
            const sales = listingSales(listing, metric);
            const matches = listingMatchesManualFilter(listing, traffic, recommendations, manualFilter);
            const selected = selectedIds.includes(listingId);
            return (
              <View key={listingId} style={[styles.productCard, selected && styles.productCardSelected]}>
              <Pressable onPress={() => toggleSelected(listingId)} style={[styles.selectToggle, selected && styles.selectToggleActive]}>
                <Text style={[styles.selectToggleText, selected && styles.selectToggleTextActive]}>{selected ? 'Selected' : 'Select'}</Text>
              </Pressable>
              {imageUrl ? <RNImage source={{ uri: imageUrl }} style={styles.queueImage} resizeMode="cover" /> : null}
              <Text style={styles.cardTitle} numberOfLines={2}>{String(listing.title ?? listing.listingId)}</Text>
              <View style={styles.iconMetricGrid}>
                <IconMetric icon={DollarSign} label="Price" value={`$${Number(listing.price ?? 0).toFixed(2)}`} tone="gold" />
                <IconMetric icon={Store} label="Avail." value={String(Number(listing.quantityAvailable ?? 0))} />
                <IconMetric icon={ShoppingCart} label="Sold" value={String(sales)} tone={sales > 0 ? 'green' : undefined} />
                <IconMetric icon={Eye} label="Views" value={hasTraffic ? String(views ?? 0) : '--'} muted={!hasTraffic} />
                <IconMetric icon={MousePointerClick} label="Clicks" value={hasTraffic ? String(clicks ?? 0) : '--'} muted={!hasTraffic} />
                <IconMetric icon={TrendingUp} label="Imp." value={hasTraffic ? String(Number(metric?.impressions ?? 0)) : '--'} muted={!hasTraffic} />
                <IconMetric icon={Heart} label="Watch" value={String(listingWatchers(listing))} />
              </View>
              <View style={styles.cardStats}>
                {matches && <Badge text="Filter match" tone="green" />}
                {!hasTraffic && <Badge text="Traffic unavailable" />}
              </View>
              {hasTraffic && <Text style={styles.microCopy}>{days || '14'}d CTR {Number(metric?.clickThroughRate ?? 0).toFixed(2)}% | Sales conv. {Number(metric?.salesConversionRate ?? 0).toFixed(2)}%</Text>}
              {rec && <Notice title={String(rec.action ?? 'review')} items={[String(rec.reason ?? '')]} />}
              <Text style={styles.muted}>Item ID {listingId}</Text>
              {listing.sku != null && <Text style={styles.muted}>SKU {String(listing.sku)}</Text>}
            </View>
            );
          })}
        </View>
      )}
    </Panel>
  );
}

function listingTraffic(listing: Record<string, unknown>, traffic: Array<Record<string, unknown>>): Record<string, unknown> | undefined {
  return traffic.find((row) => String(row.listingId) === String(listing.listingId));
}

function listingRecommendation(listing: Record<string, unknown>, recommendations: Array<Record<string, unknown>>): Record<string, unknown> | undefined {
  return recommendations.find((item) => String((item.listing as Record<string, unknown> | undefined)?.listingId) === String(listing.listingId));
}

function listingClicks(metric?: Record<string, unknown>): number {
  const views = Number(metric?.views ?? 0);
  const ctr = Number(metric?.clickThroughRate ?? 0);
  if (!Number.isFinite(views) || !Number.isFinite(ctr) || views <= 0 || ctr <= 0) return 0;
  return Math.round(views * (ctr > 1 ? ctr / 100 : ctr));
}

function listingSales(listing: Record<string, unknown>, metric?: Record<string, unknown>): number {
  return Math.max(0, Number(metric?.transactions ?? 0), Number(listing.quantitySold ?? 0));
}

function listingWatchers(listing: Record<string, unknown>): number {
  const raw = listing.raw && typeof listing.raw === 'object' ? listing.raw as Record<string, unknown> : {};
  return Math.max(0, Number(listing.watchers ?? listing.watchCount ?? raw.watchers ?? raw.watchCount ?? 0));
}

function listingMatchesManualFilter(
  listing: Record<string, unknown>,
  traffic: Array<Record<string, unknown>>,
  recommendations: Array<Record<string, unknown>>,
  filter: ManualEndFilter,
): boolean {
  const metric = listingTraffic(listing, traffic);
  const hasTraffic = Boolean(metric);
  const views = hasTraffic ? Number(metric?.views ?? 0) : Number.NaN;
  const clicks = hasTraffic ? listingClicks(metric) : Number.NaN;
  const sales = listingSales(listing, metric);
  if (filter === 'no_views') return hasTraffic && views <= 0 && sales <= 0;
  if (filter === 'no_clicks') return hasTraffic && clicks <= 0 && sales <= 0;
  if (filter === 'no_sales') return sales <= 0;
  const recommendation = listingRecommendation(listing, recommendations)?.recommendation as Record<string, unknown> | undefined;
  return ['end_listing', 'rewrite_relist'].includes(String(recommendation?.action ?? ''));
}

function manualFilterLabel(filter: ManualEndFilter): string {
  if (filter === 'no_views') return 'No views and no sales';
  if (filter === 'no_clicks') return 'No clicks and no sales';
  if (filter === 'no_sales') return 'No sales';
  return 'Optimizer says end or rewrite/relist';
}

function OptimizerScreen(props: {
  run: <T>(loadingText: string, successText: string, work: () => Promise<T>, onSuccess?: (data: T) => void) => Promise<T | undefined>;
  result: JsonValue;
  setResult: (value: JsonValue) => void;
}) {
  const [days, setDays] = useState('30');
  const [limit, setLimit] = useState('250');
  const scan = () =>
    props.run(
      'Fetching eBay listings, traffic metrics, and marketer recommendations...',
      'Autonomous optimization scan finished.',
      () =>
        api<JsonValue>(`/optimizer/scan?days=${encodeURIComponent(days || '30')}&limit=${encodeURIComponent(limit || '25')}`),
      props.setResult
    );
  const autoRun = () =>
    props.run(
      'Running autonomous optimizer through the rules engine...',
      'Autonomous optimizer run finished.',
      () => api<JsonValue>(`/optimizer/auto-run?days=${encodeURIComponent(days || '30')}&limit=${encodeURIComponent(limit || '250')}`),
      props.setResult
    );

  return (
    <View>
      <Panel title="Autonomous Listing Optimizer">
        <Text style={styles.bodyText}>This is not a manual calculator. It fetches your eBay inventory/offers, asks eBay Analytics for listing traffic, then applies marketer rules to decide whether to improve title, first image, description, price, stock status, or relist/end.</Text>
        <View style={styles.formGrid}>
          <Input label="Traffic window days" value={days} onChangeText={setDays} keyboardType="numeric" />
          <Input label="Listing scan limit" value={limit} onChangeText={setLimit} keyboardType="numeric" />
        </View>
        <View style={styles.actions}>
          <Button label="Scan eBay Listings" icon={Bot} onPress={scan} />
          <Button label="Run Autonomous Actions" icon={Wand2} onPress={autoRun} secondary />
        </View>
      </Panel>
      <OptimizationResult data={props.result} />
    </View>
  );
}

function RulesScreen({ rules, loadRules }: { rules: JsonValue; loadRules: () => void }) {
  const strategy = typeof rules === 'object' && rules && 'strategy' in rules && Array.isArray((rules as { strategy: unknown }).strategy) ? ((rules as { strategy: Array<Record<string, unknown>> }).strategy) : [];
  return (
    <View>
      <Panel title="Professional Listing Strategy Rules">
        <Text style={styles.bodyText}>These rules do not mean “end after X days” blindly. They decide what to change based on exposure, click-through, conversion, stock, cost, competitor movement, and listing age.</Text>
        <View style={styles.actions}>
          <Button label="Load Rules From Server" icon={SlidersHorizontal} onPress={loadRules} />
        </View>
        {strategy.map((item, index) => (
          <View key={index} style={styles.ruleRow}>
            <Text style={styles.ruleSignal}>{String(item.signal)}</Text>
            <Text style={styles.ruleAction}>{String(item.action)}</Text>
          </View>
        ))}
      </Panel>
    </View>
  );
}

function SettingsScreen(props: {
  status: JsonValue;
  health: JsonValue;
  logs: JsonValue;
  loadHealth: () => void;
  loadSettings: () => void;
  loadLogs: () => void;
  run: <T>(loadingText: string, successText: string, work: () => Promise<T>, onSuccess?: (data: T) => void) => Promise<T | undefined>;
}) {
  const openOAuth = () =>
    props.run('Creating eBay OAuth URL...', 'OAuth URL opened.', () => api<{ url: string }>('/ebay/oauth/start'), (data) => {
      void Linking.openURL(data.url);
    });

  return (
    <View>
      <Panel title="Credential Status">
        <Text style={styles.bodyText}>You enter credentials in the project `.env` file, not in the app UI. The TypeScript server reads those variables and the dashboard only shows safe present/missing flags plus the exact env key names.</Text>
        <View style={styles.actions}>
          <Button label="Refresh Status" icon={RefreshCcw} onPress={props.loadSettings} secondary />
          <Button label="Check Integrations" icon={CheckCircle2} onPress={props.loadHealth} />
          <Button label="Open eBay OAuth" icon={ExternalLink} onPress={openOAuth} secondary />
          <Button label="Open Logs" icon={AlertCircle} onPress={props.loadLogs} secondary />
        </View>
      </Panel>
      <EnvStatus data={props.status} />
      <HealthStatus data={props.health} />
      <LogSummary data={props.logs} />
    </View>
  );
}

function ProductCard({ product, selected, onToggleSelect, onPress }: { product: CjProductCard; selected: boolean; onToggleSelect: () => void; onPress: () => void }) {
  return (
    <View style={styles.productCard}>
      <Pressable onPress={onToggleSelect} style={[styles.selectToggle, selected && styles.selectToggleActive]}>
        <Text style={[styles.selectToggleText, selected && styles.selectToggleTextActive]}>{selected ? 'Selected' : 'Select'}</Text>
      </Pressable>
      <View style={styles.productMedia}>
        {product.image ? <RNImage source={{ uri: product.image }} style={styles.productImage} resizeMode="cover" /> : <ImageIcon color="#0f766e" size={34} />}
        {product.videos.length > 0 && <View style={styles.videoBadge}><Text style={styles.videoBadgeText}>Video</Text></View>}
      </View>
      <Text style={styles.cardTitle} numberOfLines={2}>{product.title}</Text>
      <View style={styles.cardStats}>
        <Badge text={`$${product.nowPrice ?? product.sellPrice ?? '0.00'}`} tone="green" />
        <Badge text={`${(product.inventory ?? 0).toLocaleString()} stock`} />
        <Badge text={`${(product.listedNum ?? 0).toLocaleString()} listed`} />
      </View>
      {product.sku && <Text style={styles.muted} numberOfLines={1}>SKU {product.sku}</Text>}
      <Pressable onPress={onPress} style={({ pressed }) => [styles.detailButton, pressed && styles.buttonPressed]}>
        <Text style={styles.openDetails}>Open details</Text>
      </Pressable>
    </View>
  );
}

function ProductDetailModal({ product, detail, countryCode, onClose, addToQueue }: { product: CjProductCard | null; detail: JsonValue; countryCode: string; onClose: () => void; addToQueue: (item: BulkQueueItem) => void }) {
  const [actionNote, setActionNote] = useState<string | null>(null);
  const [market, setMarket] = useState<JsonValue>(null);
  const [actionLoading, setActionLoading] = useState<string | null>(null);
  const [imageIndex, setImageIndex] = useState(0);
  const [shippingCost, setShippingCost] = useState('0');
  const [freightOptions, setFreightOptions] = useState<FreightOption[]>([]);
  const [selectedVariantId, setSelectedVariantId] = useState<string | null>(null);
  const [autoFreightKey, setAutoFreightKey] = useState('');
  useEffect(() => {
    setActionNote(null);
    setMarket(null);
    setImageIndex(0);
    setShippingCost('0');
    setFreightOptions([]);
    setSelectedVariantId(null);
    setAutoFreightKey('');
  }, [product?.id]);
  useEffect(() => {
    if (!product) return;
    const detailVariants = extractVariants(detail);
    const autoVariant = detailVariants.find((variant) => variant.id === selectedVariantId) ?? detailVariants[0];
    if (!autoVariant?.id) return;
    const nextFreightKey = `${product.id}:${autoVariant.id}:${countryCode || 'US'}`;
    if (autoFreightKey === nextFreightKey) return;
    setAutoFreightKey(nextFreightKey);
    setActionLoading('freight');
    void api<JsonValue>('/cj/freight', {
      method: 'POST',
      body: JSON.stringify({
        productId: product.id,
        variantId: autoVariant.id,
        sourceCountry: 'CN',
        destinationCountry: countryCode || 'US',
        shippingCost: 0,
      }),
    })
      .then((data) => {
        const options = extractFreightOptions(data);
        setFreightOptions(options);
        if (options[0]) setShippingCost(String(options[0].price));
      })
      .catch((error) => setActionNote(error instanceof Error ? error.message : 'CJ freight calculation failed.'))
      .finally(() => setActionLoading(null));
  }, [product?.id, detail, selectedVariantId, countryCode, autoFreightKey]);
  if (!product) return null;
  const images = extractDetailImages(detail);
  const raw = detail && typeof detail === 'object' ? (detail as Record<string, unknown>) : product.raw;
  const html = String(raw.description ?? raw.productDescription ?? product.raw.description ?? '');
  const description = textFromHtml(html);
  const variants = extractVariants(detail);
  const selectedVariant = variants.find((variant) => variant.id === selectedVariantId) ?? variants[0];
  const price = selectedVariant?.price || Number(product.nowPrice ?? product.sellPrice ?? 0);
  const inventory = selectedVariant?.inventory || product.inventory || 0;
  const marketRoot = market && typeof market === 'object' ? (market as Record<string, unknown>) : {};
  const marketCap = numberFrom(marketRoot.highestReasonablePrice ?? marketRoot.recommendedListingPrice, 0);
  const profit = calculateProfitPreview(price, numberFrom(shippingCost), marketCap || undefined);
  const gallery = [...new Set([selectedVariant?.image, ...(images.length ? images : product.image ? [product.image] : [])].filter(Boolean).map(String))];
  const activeImage = gallery[Math.min(imageIndex, Math.max(gallery.length - 1, 0))];
  const videoSources = extractVideoSources(detail, product.videos);
  const improvedTitle = product.title
    .replace(/\b(Hot Sale|New|Fashion|Dropshipping|Wholesale)\b/gi, '')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 80);
  const runMarketResearch = async () => {
    setActionLoading('market');
    setActionNote(null);
    try {
      const data = await api<JsonValue>('/ebay/market-research', {
        method: 'POST',
        body: JSON.stringify({ title: product.title }),
      });
      setMarket(data);
      setActionNote('eBay competitor research loaded for this product title.');
    } catch (error) {
      setActionNote(error instanceof Error ? error.message : 'Market research failed.');
    } finally {
      setActionLoading(null);
    }
  };
  const runAISuggestions = async (focus: 'title' | 'description' | 'image') => {
    setActionLoading(`ai-${focus}`);
    setActionNote(null);
    try {
      const data = await api<Record<string, unknown>>('/ai/listing-suggestions', {
        method: 'POST',
        body: JSON.stringify({ title: product.title, description, raw }),
      });
      if (focus === 'title') {
        setActionNote(`Suggested title: ${String(data.improvedTitle ?? product.title)}`);
      } else if (focus === 'description') {
        setActionNote(`Suggested description:\n${String(data.improvedDescription ?? '')}`);
      } else {
        const specifics = data.itemSpecifics && typeof data.itemSpecifics === 'object' ? Object.entries(data.itemSpecifics as Record<string, unknown>).slice(0, 12).map(([key, value]) => `${key}: ${String(value)}`) : [];
        setActionNote([String(data.mainImageStrategy ?? ''), specifics.length ? `Item specifics: ${specifics.join(' | ')}` : ''].filter(Boolean).join('\n'));
      }
    } catch (error) {
      setActionNote(error instanceof Error ? error.message : 'AI suggestion failed.');
    } finally {
      setActionLoading(null);
    }
  };
  const calculateFreight = async () => {
    setActionLoading('freight');
    setActionNote(null);
    try {
      const data = await api<JsonValue>('/cj/freight', {
        method: 'POST',
        body: JSON.stringify({
          productId: product.id,
          variantId: selectedVariant?.id,
          sourceCountry: 'CN',
          destinationCountry: countryCode || 'US',
          shippingCost: 0,
        }),
      });
      const options = extractFreightOptions(data);
      setFreightOptions(options);
      if (options[0]) {
        setShippingCost(String(options[0].price));
        setActionNote(`CJ logistics selected ${options[0].name} at $${options[0].price.toFixed(2)}${options[0].aging ? `, ${options[0].aging} days` : ''}.`);
      } else {
        setActionNote('CJ logistics returned no valid shipping options for this variant/destination.');
      }
    } catch (error) {
      setActionNote(error instanceof Error ? error.message : 'CJ freight calculation failed.');
    } finally {
      setActionLoading(null);
    }
  };
  const queueProduct = () => {
    addToQueue({
      id: `${product.id}:${selectedVariant?.id ?? 'product'}`,
      productId: product.id,
      variantId: selectedVariant?.id,
      categoryId: product.categoryId,
      title: product.title.slice(0, 80),
      sku: selectedVariant?.sku ?? product.sku,
      image: activeImage,
      variant: selectedVariant?.name,
      productCost: price,
      shippingCost: numberFrom(shippingCost),
      landedCost: profit.landedCost,
      ebayPrice: profit.targetPrice,
      estimatedProfit: profit.estimatedProfit,
      inventory,
      weight: selectedVariant?.weight,
      raw,
    });
    setActionNote('Added to Bulk Listing Queue with the current variant, CJ freight cost, and eBay price.');
  };

  return (
    <Modal visible transparent animationType="fade" onRequestClose={onClose}>
      <View style={styles.modalBackdrop}>
        <View style={styles.modalSheet}>
          <View style={styles.modalHeader}>
            <View style={styles.headerText}>
              <Text style={styles.eyebrow}>CJ research preview</Text>
              <Text style={styles.modalTitle} numberOfLines={2}>{product.title}</Text>
            </View>
            <Pressable onPress={onClose} style={styles.iconButton}><X size={22} color="#334e68" /></Pressable>
          </View>
          <ScrollView contentContainerStyle={styles.modalBody}>
            <View style={styles.detailLayout}>
              <View style={styles.detailMediaColumn}>
                <View style={styles.galleryFrame}>
                  {activeImage ? <RNImage source={{ uri: activeImage }} style={styles.galleryImage} resizeMode="contain" /> : <ImageIcon color="#0f766e" size={48} />}
                  {gallery.length > 1 && (
                    <>
                      <Pressable onPress={() => setImageIndex((index) => (index <= 0 ? gallery.length - 1 : index - 1))} style={[styles.galleryArrow, styles.galleryArrowLeft]}>
                        <ChevronLeft color="#ffffff" size={24} />
                      </Pressable>
                      <Pressable onPress={() => setImageIndex((index) => (index + 1) % gallery.length)} style={[styles.galleryArrow, styles.galleryArrowRight]}>
                        <ChevronRight color="#ffffff" size={24} />
                      </Pressable>
                    </>
                  )}
                </View>
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.imageStrip}>
                  {gallery.map((url, index) => (
                    <Pressable key={url} onPress={() => setImageIndex(index)} style={[styles.thumbnailButton, index === imageIndex && styles.thumbnailButtonActive]}>
                      <RNImage source={{ uri: url }} style={styles.thumbnailImage} resizeMode="cover" />
                    </Pressable>
                  ))}
                </ScrollView>
                <VideoPreview videos={product.videos} sources={videoSources} />
              </View>
              <View style={styles.detailInfoColumn}>
                <View style={styles.cardStats}>
                  <Badge text={`Inventory ${inventory.toLocaleString()}`} />
                  <Badge text={`Listed ${(product.listedNum ?? 0).toLocaleString()}`} />
                  <Badge text={`Cost $${price.toFixed(2)}`} tone="green" />
                </View>
                <VariantPicker variants={variants} selectedId={selectedVariant?.id} onSelect={setSelectedVariantId} />
                <View style={styles.formGrid}>
                  <Input label="CJ shipping cost" value={shippingCost} onChangeText={setShippingCost} placeholder="0" keyboardType="numeric" />
                </View>
                <View style={styles.actions}>
                  <Button label={actionLoading === 'freight' ? 'Calculating...' : 'Calculate CJ Shipping'} icon={RefreshCcw} secondary onPress={calculateFreight} />
                </View>
                <FreightPicker options={freightOptions} selectedPrice={numberFrom(shippingCost)} onSelect={(option) => {
                  setShippingCost(String(option.price));
                  setActionNote(`Selected ${option.name} at $${option.price.toFixed(2)}${option.aging ? `, ${option.aging} days` : ''}.`);
                }} />
                <View style={styles.miniGrid}>
                  <MetricCard label="Landed cost" value={`$${profit.landedCost.toFixed(2)}`} detail="Product cost + shipping" />
                  <MetricCard label="Break-even" value={`$${profit.breakEvenPrice.toFixed(2)}`} detail="Uses 17% eBay/ad buffer" />
                  <MetricCard label="eBay final price" value={`$${profit.targetPrice.toFixed(2)}`} detail={`Profit target $${profit.targetProfit.toFixed(2)}`} />
                  <MetricCard label="Net profit" value={`$${profit.estimatedProfit.toFixed(2)}`} detail={`${profit.marginPercent.toFixed(2)}% margin after fees`} />
                </View>
              </View>
            </View>
            <ProfitLadder baseCost={price} shippingCost={numberFrom(shippingCost)} />
            <View style={styles.actions}>
              <Button label={actionLoading === 'ai-title' ? 'Improving...' : 'AI Improve Title'} icon={Wand2} secondary onPress={() => runAISuggestions('title')} />
              <Button label={actionLoading === 'ai-description' ? 'Writing...' : 'AI Improve Description'} icon={Bot} secondary onPress={() => runAISuggestions('description')} />
              <Button label={actionLoading === 'ai-image' ? 'Scoring...' : 'AI Image + Specifics'} icon={ImageIcon} secondary onPress={() => runAISuggestions('image')} />
              <Button label={actionLoading === 'market' ? 'Researching...' : 'Research eBay Competitors'} icon={Store} secondary onPress={runMarketResearch} />
              <Button label="Add to Bulk Queue" icon={ClipboardList} onPress={queueProduct} />
            </View>
            {actionNote && <Notice title="Action result" items={[actionNote]} />}
            <MarketPreview data={market} />
            <DescriptionPreview description={description} images={images} />
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
}

function MarketPreview({ data }: { data: JsonValue }) {
  const root = data && typeof data === 'object' ? (data as Record<string, unknown>) : {};
  if (!data) return null;
  return (
    <View style={styles.miniGrid}>
      <MetricCard label="Average price" value={`$${Number(root.averagePrice ?? 0).toFixed(2)}`} detail="From eBay comparable listings" />
      <MetricCard label="Median price" value={`$${Number(root.medianPrice ?? 0).toFixed(2)}`} detail="Used to avoid chasing outliers" />
      <MetricCard label="Suggested cap" value={`$${Number(root.recommendedListingPrice ?? 0).toFixed(2)}`} detail="Before final margin guard" />
    </View>
  );
}

function VariantPicker({ variants, selectedId, onSelect }: { variants: VariantSummary[]; selectedId?: string; onSelect: (id: string) => void }) {
  if (variants.length === 0) return <Notice title="Variants" items={['CJ detail has not returned variant rows yet. The preview uses the product-level price until a variant is available.']} />;
  return (
    <View style={styles.variantPanel}>
      <Text style={styles.inputLabel}>Variants</Text>
      <ScrollView style={styles.variantList} nestedScrollEnabled>
        {variants.map((variant) => {
          const selected = variant.id === selectedId;
          return (
            <Pressable key={variant.id} onPress={() => onSelect(variant.id)} style={[styles.variantRow, selected && styles.variantRowActive]}>
              {variant.image && <RNImage source={{ uri: variant.image }} style={styles.variantImage} resizeMode="cover" />}
              <View style={styles.variantText}>
                <Text style={[styles.variantName, selected && styles.variantNameActive]} numberOfLines={2}>{variant.name}</Text>
                <Text style={[styles.muted, selected && styles.variantSubActive]} numberOfLines={1}>{variant.sku ?? 'No SKU'} {variant.attributes ? `| ${variant.attributes}` : ''}</Text>
              </View>
              <View style={styles.variantNumbers}>
                <Text style={[styles.variantPrice, selected && styles.variantNameActive]}>${variant.price.toFixed(2)}</Text>
                <Text style={[styles.muted, selected && styles.variantSubActive]}>{variant.inventory.toLocaleString()} stock</Text>
              </View>
            </Pressable>
          );
        })}
      </ScrollView>
    </View>
  );
}

function FreightPicker({ options, selectedPrice, onSelect }: { options: FreightOption[]; selectedPrice: number; onSelect: (option: FreightOption) => void }) {
  if (options.length === 0) return null;
  return (
    <View style={styles.freightPanel}>
      <Text style={styles.inputLabel}>CJ logistics options</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.freightStrip}>
        {options.slice(0, 10).map((option) => {
          const selected = Math.abs(option.price - selectedPrice) < 0.01;
          return (
            <Pressable key={`${option.name}-${option.price}`} onPress={() => onSelect(option)} style={[styles.freightOption, selected && styles.freightOptionActive]}>
              <Text style={[styles.freightName, selected && styles.freightTextActive]} numberOfLines={1}>{option.name}</Text>
              <Text style={[styles.freightPrice, selected && styles.freightTextActive]}>${option.price.toFixed(2)}</Text>
              {option.aging && <Text style={[styles.muted, selected && styles.freightSubActive]}>{option.aging} days</Text>}
            </Pressable>
          );
        })}
      </ScrollView>
    </View>
  );
}

function VideoPreview({ videos, sources }: { videos: string[]; sources: string[] }) {
  if (videos.length === 0 && sources.length === 0) return <Text style={styles.muted}>No CJ video assets returned for this product.</Text>;
  return (
    <View style={styles.videoPanel}>
      <Text style={styles.inputLabel}>Video assets</Text>
      {sources.length > 0 && (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.videoStrip}>
          {sources.map((source) => (
            <View key={source} style={styles.videoPlayerCard}>
              {typeof document !== 'undefined'
                ? ReactVideo({ source })
                : <Text style={styles.muted}>Video preview available on web.</Text>}
            </View>
          ))}
        </ScrollView>
      )}
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.videoStrip}>
        {videos.slice(0, 12).map((video, index) => (
          <View key={video} style={styles.videoChip}>
            <Text style={styles.videoChipTitle}>Video {index + 1}</Text>
            <Text style={styles.videoChipText} numberOfLines={1}>{video}</Text>
          </View>
        ))}
      </ScrollView>
      {sources.length === 0 && videos.length > 0 && <Text style={styles.muted}>CJ returned video IDs, but the website player uses temporary blob URLs created in the browser. The app keeps these IDs for media tracking; direct playback/download needs a CJ video file endpoint or a browser capture worker.</Text>}
    </View>
  );
}

function ReactVideo({ source }: { source: string }) {
  return (
    // React Native Web passes this element through on web; native builds still show the surrounding fallback path.
    <video src={source} controls style={{ width: '100%', height: '100%', borderRadius: 8, backgroundColor: '#102a43' }} />
  );
}

function ProfitLadder({ baseCost, shippingCost }: { baseCost: number; shippingCost: number }) {
  const rows = profitLadderRows(baseCost, shippingCost);
  return (
    <View style={styles.ladderPanel}>
      <Text style={styles.panelTitle}>Profit Ladder</Text>
      <Text style={styles.bodyText}>The target profit increases gradually from small products to $450+ landed-cost products, with the 17% eBay/ad buffer shown in every row.</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false}>
        <View style={styles.ladderTable}>
          <View style={[styles.ladderRow, styles.ladderHeader]}>
            {['Landed', 'Break-even', 'Target profit', 'eBay price', 'Fees', 'Net profit', 'Margin'].map((label) => <Text key={label} style={styles.ladderHeadCell}>{label}</Text>)}
          </View>
          {rows.map((row) => (
            <View key={`${row.landedCost}-${row.targetPrice}`} style={[styles.ladderRow, Math.abs(row.landedCost - (baseCost + shippingCost)) < 0.01 && styles.ladderRowActive]}>
              <Text style={styles.ladderCell}>${row.landedCost.toFixed(2)}</Text>
              <Text style={styles.ladderCell}>${row.breakEvenPrice.toFixed(2)}</Text>
              <Text style={styles.ladderCell}>${row.targetProfit.toFixed(2)}</Text>
              <Text style={styles.ladderCellStrong}>${row.targetPrice.toFixed(2)}</Text>
              <Text style={styles.ladderCell}>${row.estimatedFees.toFixed(2)}</Text>
              <Text style={styles.ladderCellStrong}>${row.estimatedProfit.toFixed(2)}</Text>
              <Text style={styles.ladderCell}>{row.marginPercent.toFixed(1)}%</Text>
            </View>
          ))}
        </View>
      </ScrollView>
    </View>
  );
}

function DescriptionPreview({ description, images }: { description: string; images: string[] }) {
  if (!description) {
    return <Notice title="Description preview" items={['CJ did not return a clean HTML description for this product yet. Product detail/variant endpoints may contain richer data.']} />;
  }
  return (
    <View style={styles.descriptionPreview}>
      <Text style={styles.inputLabel}>Description preview</Text>
      {images.length > 0 && (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.descriptionImages}>
          {images.slice(0, 8).map((url) => <RNImage key={url} source={{ uri: url }} style={styles.descriptionImage} resizeMode="cover" />)}
        </ScrollView>
      )}
      <Text style={styles.bodyText} numberOfLines={10}>{description}</Text>
    </View>
  );
}

function Pagination({ current, total, onPage }: { current: number; total: number; onPage: (page: number) => void }) {
  const pages = Array.from(new Set([1, 2, 3, current - 1, current, current + 1, total - 2, total - 1, total].filter((page) => page >= 1 && page <= total))).sort((a, b) => a - b);
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.pagination}>
      {pages.map((page, index) => (
        <View key={page} style={styles.pageGroup}>
          {index > 0 && page - pages[index - 1] > 1 && <Text style={styles.pageEllipsis}>...</Text>}
          <Pressable onPress={() => onPage(page)} style={[styles.pageButton, page === current && styles.pageButtonActive]}>
            <Text style={[styles.pageText, page === current && styles.pageTextActive]}>{page}</Text>
          </Pressable>
        </View>
      ))}
    </ScrollView>
  );
}

function Segmented({ label, values, selected, onSelect }: { label: string; values: number[]; selected: number; onSelect: (value: number) => void }) {
  return (
    <View style={styles.segmentWrap}>
      <Text style={styles.inputLabel}>{label}</Text>
      <View style={styles.segmentRow}>
        {values.map((value) => (
          <Pressable key={value} onPress={() => onSelect(value)} style={[styles.segmentButton, selected === value && styles.segmentButtonActive]}>
            <Text style={[styles.segmentText, selected === value && styles.segmentTextActive]}>{value}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

function SelectPills({ label, options, selected, onSelect }: { label: string; options: Array<[string, string]>; selected: string; onSelect: (value: string) => void }) {
  return (
    <View style={styles.segmentWrap}>
      <Text style={styles.inputLabel}>{label}</Text>
      <View style={styles.segmentRow}>
        {options.map(([value, text]) => (
          <Pressable key={value} onPress={() => onSelect(value)} style={[styles.segmentButton, selected === value && styles.segmentButtonActive]}>
            <Text style={[styles.segmentText, selected === value && styles.segmentTextActive]}>{text}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

function CategoryDropdown({ title, options, selected, onSelect }: { title: string; options: CategoryOption[]; selected: CategoryOption | null; onSelect: (option: CategoryOption | null) => void }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const visibleOptions = options.filter((option) => (option.path ?? option.name).toLowerCase().includes(query.toLowerCase()));
  if (options.length === 0) return <Text style={styles.muted}>No categories loaded yet.</Text>;
  return (
    <View style={styles.dropdownWrap}>
      <Text style={styles.inputLabel}>{title}</Text>
      <Pressable accessibilityRole="button" onPress={() => setOpen((value) => !value)} style={styles.dropdownControl}>
        <Text style={styles.dropdownText}>{selected?.name ?? 'All CJ categories'}</Text>
        <ChevronRight size={18} color="#52606d" style={open ? styles.dropdownChevronOpen : undefined} />
      </Pressable>
      {open && (
        <View style={styles.dropdownMenu}>
          <TextInput value={query} onChangeText={setQuery} placeholder="Search full CJ category tree..." placeholderTextColor="#94a3b8" style={styles.dropdownSearch} />
          <ScrollView nestedScrollEnabled style={styles.dropdownScroll}>
            <Pressable accessibilityRole="button" onPress={() => { onSelect(null); setOpen(false); }} style={[styles.dropdownOption, !selected && styles.dropdownOptionActive]}>
              <Text style={[styles.dropdownOptionText, !selected && styles.dropdownOptionTextActive]}>All CJ categories</Text>
            </Pressable>
            {visibleOptions.map((option) => {
              const active = option.id === selected?.id;
              return (
                <Pressable accessibilityRole="button" key={option.id} onPress={() => { onSelect(option); setOpen(false); }} style={[styles.dropdownOption, active && styles.dropdownOptionActive]}>
                  <Text style={[styles.dropdownOptionText, active && styles.dropdownOptionTextActive]}>{option.path ?? option.name}</Text>
                </Pressable>
              );
            })}
          </ScrollView>
        </View>
      )}
    </View>
  );
}

function MetricCard({ label, value, detail }: { label: string; value: string; detail: string }) {
  return (
    <View style={styles.metricCard}>
      <Text style={styles.metricLabel}>{label}</Text>
      <Text style={styles.metricValue}>{value}</Text>
      <Text style={styles.muted}>{detail}</Text>
    </View>
  );
}

function IconMetric({ icon: Icon, label, value, tone, muted }: { icon: IconType; label: string; value: string; tone?: 'green' | 'gold'; muted?: boolean }) {
  const color = muted ? '#94a3b8' : tone === 'gold' ? '#a16207' : tone === 'green' ? '#0f766e' : '#334e68';
  return (
    <View style={[styles.iconMetric, tone === 'gold' && styles.iconMetricGold, tone === 'green' && styles.iconMetricGreen, muted && styles.iconMetricMuted]}>
      <Icon size={15} color={color} />
      <View style={styles.iconMetricText}>
        <Text style={[styles.iconMetricValue, { color }]} numberOfLines={1}>{value}</Text>
        <Text style={styles.iconMetricLabel} numberOfLines={1}>{label}</Text>
      </View>
    </View>
  );
}

function Notice({ title, items }: { title: string; items: string[] }) {
  return (
    <View style={styles.notice}>
      <Text style={styles.noticeTitle}>{title}</Text>
      {items.map((item) => <Text key={item} style={styles.noticeItem}>{item}</Text>)}
    </View>
  );
}

function Badge({ text, tone }: { text: string; tone?: 'green' }) {
  return (
    <View style={[styles.badge, tone === 'green' && styles.badgeGreen]}>
      <Text style={[styles.badgeText, tone === 'green' && styles.badgeTextGreen]}>{text}</Text>
    </View>
  );
}

function EnvStatus({ data }: { data: JsonValue }) {
  const root = data && typeof data === 'object' ? (data as Record<string, Record<string, unknown>>) : {};
  return (
    <Panel title="Environment Variables">
      <View style={styles.resultGrid}>
        {Object.entries(root).map(([provider, values]) => (
          <View key={provider} style={styles.statusCard}>
            <Text style={styles.cardTitle}>{provider.toUpperCase()}</Text>
            {Object.entries(values).filter(([key]) => key.startsWith('has')).map(([key, value]) => (
              <Text key={key} style={value ? styles.goodLine : styles.badLine}>{key.replace(/^has/, '')}: {value ? 'present' : 'missing'}</Text>
            ))}
          </View>
        ))}
      </View>
    </Panel>
  );
}

function HealthStatus({ data }: { data: JsonValue }) {
  const integrations = data && typeof data === 'object' && Array.isArray((data as { integrations?: unknown }).integrations) ? ((data as { integrations: Array<Record<string, unknown>> }).integrations) : [];
  if (integrations.length === 0) return null;
  return (
    <Panel title="Integration Health">
      <View style={styles.resultGrid}>
        {integrations.map((integration) => (
          <View key={String(integration.provider)} style={styles.statusCard}>
            <Text style={styles.cardTitle}>{String(integration.provider).toUpperCase()}</Text>
            <Badge text={String(integration.status)} tone={integration.status === 'connected' ? 'green' : undefined} />
            <Text style={styles.muted}>{String(integration.message)}</Text>
          </View>
        ))}
      </View>
    </Panel>
  );
}

function LogSummary({ data }: { data: JsonValue }) {
  const root = data && typeof data === 'object' ? (data as Record<string, unknown>) : {};
  const auditCount = Array.isArray(root.auditLogs) ? root.auditLogs.length : 0;
  const jobCount = Array.isArray(root.jobLogs) ? root.jobLogs.length : 0;
  if (!data) return null;
  return (
    <Panel title="Logs">
      <View style={styles.miniGrid}>
        <MetricCard label="Audit logs" value={String(auditCount)} detail="Traceable automation actions" />
        <MetricCard label="Job logs" value={String(jobCount)} detail="Background/API job outcomes" />
      </View>
    </Panel>
  );
}

function StatusCard({ title, detail, data }: { title: string; detail: string; data: JsonValue }) {
  return (
    <View style={styles.statusCard}>
      <Text style={styles.cardTitle}>{title}</Text>
      <Text style={styles.muted}>{detail}</Text>
      <Text style={styles.statusText}>{data ? 'Data loaded' : 'Not checked'}</Text>
    </View>
  );
}

function MessageBar({ message }: { message: AppMessage }) {
  const color = message.kind === 'error' ? '#991b1b' : message.kind === 'success' ? '#0f766e' : message.kind === 'loading' ? '#1d4ed8' : '#52606d';
  return (
    <View style={styles.messageBar}>
      {message.kind === 'loading' ? <Loader2 size={17} color={color} /> : <CheckCircle2 size={17} color={color} />}
      <Text style={[styles.messageText, { color }]}>{message.text}</Text>
    </View>
  );
}

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <View style={styles.panel}>
      <Text style={styles.panelTitle}>{title}</Text>
      {children}
    </View>
  );
}

function EmptyState({ title, detail }: { title: string; detail: string }) {
  return (
    <View style={styles.empty}>
      <ImageIcon color="#0f766e" size={36} />
      <Text style={styles.emptyTitle}>{title}</Text>
      <Text style={styles.emptyDetail}>{detail}</Text>
    </View>
  );
}

function OptimizationResult({ data }: { data: JsonValue }) {
  if (!data || typeof data !== 'object') return <EmptyState title="No scan yet" detail="Click Scan eBay Listings to let the system fetch eBay listing metrics and generate recommendations." />;
  const root = data as Record<string, unknown>;
  const recommendations = Array.isArray(root.recommendations) ? (root.recommendations as Array<Record<string, unknown>>) : [];
  const actions = Array.isArray(root.actions) ? (root.actions as Array<Record<string, unknown>>) : [];
  const warnings = Array.isArray(root.warnings) ? root.warnings.map(String) : [];
  return (
    <Panel title="Autonomous Scan Result">
      <View style={styles.miniGrid}>
        <MetricCard label="Listings scanned" value={String(Array.isArray(root.listings) ? root.listings.length : 0)} detail={`${String(root.windowDays ?? 30)} day traffic window`} />
        <MetricCard label="Actions proposed" value={String(recommendations.length)} detail="Approval queue candidates" />
        <MetricCard label="Auto execution" value={root.executionAllowed ? 'Live' : 'Guarded'} detail={root.dryRun ? 'Dry-run or not full-auto' : String(root.mode ?? 'approval')} />
        <MetricCard label="Warnings" value={String(warnings.length)} detail="Data gaps or API limitations" />
      </View>
      {warnings.length > 0 && <Notice title="Scan warnings" items={warnings} />}
      {actions.length > 0 && (
        <View style={styles.resultGrid}>
          {actions.slice(0, 30).map((action) => (
            <View key={`${String(action.listingId)}-${String(action.recommendation)}`} style={styles.productCard}>
              <Text style={styles.cardTitle} numberOfLines={2}>{String(action.title ?? action.listingId)}</Text>
              <Badge text={String(action.execution)} tone={action.executed ? 'green' : undefined} />
              <Text style={styles.muted}>{String(action.reason ?? '')}</Text>
              {action.quantityTopUp != null && <Text style={styles.statusText}>Quantity target: {String(action.quantityTopUp)}</Text>}
            </View>
          ))}
        </View>
      )}
      <View style={styles.resultGrid}>
        {recommendations.map((item, index) => {
          const recommendation = item.recommendation as Record<string, unknown> | undefined;
          const listing = item.listing as Record<string, unknown> | undefined;
          const performance = item.performance as Record<string, unknown> | undefined;
          const trafficAvailable = Boolean(performance?.trafficAvailable);
          return (
            <View key={index} style={styles.productCard}>
              {typeof listing?.imageUrl === 'string' && <RNImage source={{ uri: listing.imageUrl }} style={styles.queueImage} resizeMode="cover" />}
              <Text style={styles.cardTitle} numberOfLines={2}>{String(listing?.title ?? listing?.listingId ?? 'Listing')}</Text>
              <Badge text={String(recommendation?.action ?? 'review')} />
              <View style={styles.iconMetricGrid}>
                <IconMetric icon={DollarSign} label="Price" value={`$${Number(listing?.price ?? 0).toFixed(2)}`} tone="gold" />
                <IconMetric icon={Store} label="Avail." value={String(Number(listing?.quantityAvailable ?? 0))} />
                <IconMetric icon={ShoppingCart} label="Sold" value={String(Number(performance?.sales ?? listing?.quantitySold ?? 0))} tone={Number(performance?.sales ?? listing?.quantitySold ?? 0) > 0 ? 'green' : undefined} />
                <IconMetric icon={Eye} label="Views" value={trafficAvailable ? String(Number(performance?.views ?? 0)) : '--'} muted={!trafficAvailable} />
                <IconMetric icon={MousePointerClick} label="Clicks" value={trafficAvailable ? String(Number(performance?.clicks ?? 0)) : '--'} muted={!trafficAvailable} />
              </View>
              <Text style={styles.muted}>{String(recommendation?.reason ?? '')}</Text>
            </View>
          );
        })}
      </View>
    </Panel>
  );
}

function Input(props: { label: string; value?: string; onChangeText?: (value: string) => void; placeholder?: string; secure?: boolean; keyboardType?: 'default' | 'numeric' }) {
  return (
    <View style={styles.inputWrap}>
      <Text style={styles.inputLabel}>{props.label}</Text>
      <TextInput secureTextEntry={props.secure} value={props.value} onChangeText={props.onChangeText} placeholder={props.placeholder} placeholderTextColor="#94a3b8" keyboardType={props.keyboardType ?? 'default'} style={styles.input} />
    </View>
  );
}

function Button({ label, icon: Icon, secondary, danger, disabled, onPress }: { label: string; icon: IconType; secondary?: boolean; danger?: boolean; disabled?: boolean; onPress?: () => void }) {
  return (
    <Pressable
      onPress={disabled ? undefined : onPress}
      style={({ pressed }) => [styles.button, secondary && styles.secondaryButton, danger && styles.dangerButton, disabled && styles.disabledButton, pressed && !disabled && styles.buttonPressed]}
    >
      <Icon size={18} color={secondary ? '#0f766e' : '#ffffff'} />
      <Text style={[styles.buttonText, secondary && styles.secondaryButtonText]}>{label}</Text>
    </Pressable>
  );
}

function Picker({ title, options, selectedId, onSelect }: { title: string; options: CategoryOption[]; selectedId?: string; onSelect: (option: CategoryOption) => void }) {
  if (options.length === 0) return <Text style={styles.muted}>No categories loaded yet.</Text>;
  return (
    <View style={styles.picker}>
      <Text style={styles.inputLabel}>{title}</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.pickerScroll} contentContainerStyle={styles.pickerContent}>
        {options.map((option) => {
          const selected = option.id === selectedId;
          return (
            <Pressable key={option.id} onPress={() => onSelect(option)} style={[styles.pill, selected && styles.pillSelected]}>
              <Text style={[styles.pillText, selected && styles.pillTextSelected]}>{option.name}</Text>
            </Pressable>
          );
        })}
      </ScrollView>
    </View>
  );
}

function Step({ done, label }: { done?: boolean; label: string }) {
  return (
    <View style={styles.step}>
      <CheckCircle2 size={18} color={done ? '#0f766e' : '#94a3b8'} />
      <Text style={styles.stepText}>{label}</Text>
    </View>
  );
}

function ChecklistItem({ text }: { text: string }) {
  return (
    <View style={styles.checkItem}>
      <CheckCircle2 size={17} color="#0f766e" />
      <Text style={styles.checkText}>{text}</Text>
    </View>
  );
}

function TwoColumn({ left, right }: { left: React.ReactNode; right: React.ReactNode }) {
  return (
    <View style={styles.twoColumn}>
      <View style={styles.column}>{left}</View>
      <View style={styles.column}>{right}</View>
    </View>
  );
}

const styles = StyleSheet.create({
  app: { flex: 1, backgroundColor: '#f3f7ee' },
  shell: { flex: 1 },
  shellWide: { flexDirection: 'row' },
  sidebar: { backgroundColor: '#111813', borderRightWidth: 1, borderRightColor: '#d6b752', padding: 18, width: 270 },
  sidebarMobile: { width: '100%', borderRightWidth: 0, borderBottomWidth: 1, borderBottomColor: '#d6b752' },
  brandRow: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 18 },
  brand: { fontSize: 18, fontWeight: '800', color: '#f8fafc' },
  brandSub: { fontSize: 12, color: '#d6b752', marginTop: 2 },
  mobileNavContent: { gap: 8 },
  navItem: { minHeight: 42, flexDirection: 'row', alignItems: 'center', gap: 9, paddingHorizontal: 12, borderRadius: 8, marginBottom: 6 },
  navItemActive: { backgroundColor: '#f2cf63' },
  navText: { color: '#cbd5e1', fontWeight: '700', fontSize: 14 },
  navTextActive: { color: '#111813' },
  content: { flex: 1 },
  contentInner: { padding: 20, gap: 14 },
  header: { minHeight: 116, backgroundColor: '#111813', borderRadius: 8, padding: 18, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 16, borderWidth: 1, borderColor: '#d6b752' },
  headerText: { flex: 1, minWidth: 260 },
  eyebrow: { color: '#a16207', fontSize: 12, textTransform: 'uppercase', fontWeight: '800' },
  title: { color: '#f8fafc', fontSize: 32, fontWeight: '900', marginTop: 4 },
  headerCopy: { color: '#dbe4d2', marginTop: 8, lineHeight: 21, maxWidth: 760 },
  headerBadge: { flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: '#f2cf63', borderRadius: 8, paddingHorizontal: 12, paddingVertical: 10 },
  headerBadgeText: { color: '#111813', fontWeight: '800' },
  iconButton: { width: 42, height: 42, borderRadius: 8, alignItems: 'center', justifyContent: 'center', backgroundColor: '#f8fafc', borderWidth: 1, borderColor: '#dbe7e4' },
  messageBar: { minHeight: 44, backgroundColor: '#ffffff', borderRadius: 8, borderWidth: 1, borderColor: '#d5e7e2', paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', gap: 9 },
  messageText: { fontWeight: '800', flex: 1 },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 12 },
  metricGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, marginTop: 14 },
  miniGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 12 },
  metricCard: { flexGrow: 1, flexBasis: 170, backgroundColor: '#fffdf4', borderRadius: 8, borderWidth: 1, borderColor: '#ead089', padding: 14 },
  metricLabel: { color: '#52606d', fontWeight: '800', fontSize: 12 },
  metricValue: { color: '#102a43', fontSize: 24, fontWeight: '900', marginTop: 8 },
  healthGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 14, marginTop: 14 },
  statusCard: { flexGrow: 1, flexBasis: 280, backgroundColor: '#ffffff', borderRadius: 8, padding: 16, borderWidth: 1, borderColor: '#d5e7e2' },
  cardTitle: { fontSize: 16, color: '#102a43', fontWeight: '800' },
  statusText: { marginTop: 10, color: '#0f766e', fontWeight: '900' },
  muted: { color: '#62748a', lineHeight: 20, marginTop: 6 },
  bodyText: { color: '#334e68', lineHeight: 22, fontWeight: '600' },
  panel: { backgroundColor: '#ffffff', borderRadius: 8, padding: 18, borderWidth: 1, borderColor: '#e0d5a8', marginTop: 14 },
  panelTitle: { color: '#102a43', fontWeight: '900', fontSize: 18, marginBottom: 14 },
  step: { flexDirection: 'row', alignItems: 'center', gap: 9, minHeight: 34 },
  stepText: { color: '#334e68', fontWeight: '700', flex: 1 },
  formGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  filterGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  filterSummary: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 12 },
  inputWrap: { flexGrow: 1, flexBasis: 220 },
  inputLabel: { color: '#334e68', fontWeight: '800', marginBottom: 7, fontSize: 13 },
  input: { minHeight: 44, borderRadius: 8, borderWidth: 1, borderColor: '#cbd5e1', paddingHorizontal: 12, backgroundColor: '#f8fafc', color: '#102a43' },
  button: { minHeight: 42, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, backgroundColor: '#0f766e', borderRadius: 8, paddingHorizontal: 14 },
  buttonPressed: { opacity: 0.78 },
  secondaryButton: { backgroundColor: '#effaf7', borderWidth: 1, borderColor: '#99f6e4' },
  dangerButton: { backgroundColor: '#b42318' },
  disabledButton: { opacity: 0.45 },
  buttonText: { color: '#ffffff', fontWeight: '900' },
  secondaryButtonText: { color: '#0f766e' },
  resultSummary: { color: '#334e68', fontWeight: '900', marginTop: 12 },
  dropdownWrap: { marginTop: 16, zIndex: 20 },
  dropdownControl: { minHeight: 46, borderRadius: 8, borderWidth: 1, borderColor: '#cbd5e1', backgroundColor: '#f8fafc', paddingHorizontal: 12, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 },
  dropdownText: { color: '#102a43', fontWeight: '800', flex: 1 },
  dropdownChevronOpen: { transform: [{ rotate: '90deg' }] },
  dropdownMenu: { marginTop: 6, borderRadius: 8, borderWidth: 1, borderColor: '#cbd5e1', backgroundColor: '#ffffff', overflow: 'hidden' },
  dropdownSearch: { minHeight: 42, borderBottomWidth: 1, borderBottomColor: '#e2e8f0', paddingHorizontal: 12, color: '#102a43', fontWeight: '700' },
  dropdownScroll: { maxHeight: 320 },
  dropdownOption: { minHeight: 42, justifyContent: 'center', paddingHorizontal: 12, borderBottomWidth: 1, borderBottomColor: '#eef2f7' },
  dropdownOptionActive: { backgroundColor: '#0f766e' },
  dropdownOptionText: { color: '#334e68', fontWeight: '800' },
  dropdownOptionTextActive: { color: '#ffffff' },
  picker: { marginTop: 16 },
  pickerScroll: { maxHeight: 74 },
  pickerContent: { gap: 8, paddingVertical: 4 },
  pill: { minWidth: 150, borderRadius: 8, borderWidth: 1, borderColor: '#cbd5e1', backgroundColor: '#f8fafc', paddingHorizontal: 12, paddingVertical: 10 },
  pillSelected: { backgroundColor: '#0f766e', borderColor: '#0f766e' },
  pillText: { color: '#102a43', fontWeight: '800' },
  pillTextSelected: { color: '#ffffff' },
  resultGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, marginTop: 14 },
  productCard: { flexGrow: 1, flexBasis: 260, backgroundColor: '#ffffff', borderRadius: 8, padding: 14, borderWidth: 1, borderColor: '#e0d5a8' },
  productCardSelected: { borderColor: '#d6b752', backgroundColor: '#fffdf4' },
  selectToggle: { alignSelf: 'flex-start', minHeight: 30, borderRadius: 8, borderWidth: 1, borderColor: '#cbd5e1', paddingHorizontal: 10, justifyContent: 'center', marginBottom: 10, backgroundColor: '#f8fafc' },
  selectToggleActive: { backgroundColor: '#0f766e', borderColor: '#0f766e' },
  selectToggleText: { color: '#334e68', fontWeight: '900', fontSize: 12 },
  selectToggleTextActive: { color: '#ffffff' },
  draftPreview: { marginTop: 12, borderTopWidth: 1, borderTopColor: '#dbe7e4', paddingTop: 12 },
  runSummary: { marginTop: 12, borderRadius: 8, borderWidth: 1, borderColor: '#e0d5a8', backgroundColor: '#fffdf4', padding: 12 },
  inlineSummary: { marginTop: 10 },
  iconMetricGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 12 },
  iconMetric: { minWidth: 78, flexGrow: 1, flexBasis: 78, minHeight: 48, borderRadius: 8, borderWidth: 1, borderColor: '#dbe7e4', backgroundColor: '#f8fafc', paddingHorizontal: 8, paddingVertical: 7, flexDirection: 'row', alignItems: 'center', gap: 7 },
  iconMetricGold: { borderColor: '#ead089', backgroundColor: '#fff8d7' },
  iconMetricGreen: { borderColor: '#99f6e4', backgroundColor: '#effaf7' },
  iconMetricMuted: { opacity: 0.72 },
  iconMetricText: { flex: 1, minWidth: 0 },
  iconMetricValue: { fontWeight: '900', fontSize: 14 },
  iconMetricLabel: { color: '#62748a', fontWeight: '800', fontSize: 10, marginTop: 1 },
  microCopy: { marginTop: 8, color: '#62748a', fontSize: 12, fontWeight: '800' },
  productMedia: { height: 180, borderRadius: 8, backgroundColor: '#effaf7', alignItems: 'center', justifyContent: 'center', overflow: 'hidden', marginBottom: 12 },
  productImage: { width: '100%', height: '100%' },
  videoBadge: { position: 'absolute', top: 10, right: 10, backgroundColor: '#102a43', borderRadius: 8, paddingHorizontal: 8, paddingVertical: 5 },
  videoBadgeText: { color: '#ffffff', fontWeight: '900', fontSize: 11 },
  cardStats: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 10 },
  detailButton: { minHeight: 38, borderRadius: 8, borderWidth: 1, borderColor: '#99f6e4', backgroundColor: '#effaf7', alignItems: 'center', justifyContent: 'center', marginTop: 12 },
  openDetails: { color: '#0f766e', fontWeight: '900' },
  badge: { borderRadius: 8, paddingHorizontal: 8, paddingVertical: 5, backgroundColor: '#eef2f7', alignSelf: 'flex-start' },
  badgeGreen: { backgroundColor: '#d9f6ee' },
  badgeText: { color: '#334e68', fontWeight: '900', fontSize: 12 },
  badgeTextGreen: { color: '#0f766e' },
  modalBackdrop: { flex: 1, backgroundColor: 'rgba(15, 23, 42, 0.55)', padding: 18, justifyContent: 'center' },
  modalSheet: { maxHeight: '94%', width: '100%', maxWidth: 1180, alignSelf: 'center', backgroundColor: '#ffffff', borderRadius: 8, overflow: 'hidden', borderWidth: 1, borderColor: '#d5e7e2' },
  modalHeader: { minHeight: 72, padding: 16, borderBottomWidth: 1, borderBottomColor: '#e2e8f0', flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 },
  modalTitle: { color: '#102a43', fontWeight: '900', fontSize: 20, lineHeight: 25 },
  modalBody: { padding: 16, paddingBottom: 28 },
  detailLayout: { flexDirection: 'row', flexWrap: 'wrap', gap: 16 },
  detailMediaColumn: { flexGrow: 1, flexBasis: 320 },
  detailInfoColumn: { flexGrow: 1, flexBasis: 320 },
  galleryFrame: { height: 390, borderRadius: 8, backgroundColor: '#f8fafc', borderWidth: 1, borderColor: '#dbe7e4', alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  galleryImage: { width: '100%', height: '100%' },
  galleryArrow: { position: 'absolute', top: '46%', width: 42, height: 42, borderRadius: 8, backgroundColor: 'rgba(15, 42, 67, 0.82)', alignItems: 'center', justifyContent: 'center' },
  galleryArrowLeft: { left: 10 },
  galleryArrowRight: { right: 10 },
  imageStrip: { gap: 10, paddingVertical: 4 },
  thumbnailButton: { width: 72, height: 72, borderRadius: 8, borderWidth: 2, borderColor: 'transparent', overflow: 'hidden', backgroundColor: '#effaf7' },
  thumbnailButtonActive: { borderColor: '#0f766e' },
  thumbnailImage: { width: '100%', height: '100%' },
  detailImage: { width: 160, height: 160, borderRadius: 8, backgroundColor: '#effaf7' },
  detailTitle: { color: '#102a43', fontWeight: '900', fontSize: 22, lineHeight: 28 },
  variantPanel: { marginTop: 14 },
  variantList: { maxHeight: 260, borderRadius: 8, borderWidth: 1, borderColor: '#dbe7e4', backgroundColor: '#f8fafc' },
  variantRow: { minHeight: 72, flexDirection: 'row', alignItems: 'center', gap: 10, padding: 10, borderBottomWidth: 1, borderBottomColor: '#e2e8f0' },
  variantRowActive: { backgroundColor: '#0f766e' },
  variantImage: { width: 52, height: 52, borderRadius: 8, backgroundColor: '#effaf7' },
  variantText: { flex: 1 },
  variantName: { color: '#102a43', fontWeight: '900', lineHeight: 19 },
  variantNameActive: { color: '#ffffff' },
  variantSubActive: { color: '#d9f6ee' },
  variantNumbers: { minWidth: 86, alignItems: 'flex-end' },
  variantPrice: { color: '#0f766e', fontWeight: '900' },
  freightPanel: { marginTop: 12 },
  freightStrip: { gap: 8, paddingVertical: 4 },
  freightOption: { width: 170, borderRadius: 8, borderWidth: 1, borderColor: '#cbd5e1', backgroundColor: '#f8fafc', padding: 10 },
  freightOptionActive: { backgroundColor: '#0f766e', borderColor: '#0f766e' },
  freightName: { color: '#102a43', fontWeight: '900' },
  freightPrice: { color: '#0f766e', fontWeight: '900', marginTop: 6 },
  freightTextActive: { color: '#ffffff' },
  freightSubActive: { color: '#d9f6ee' },
  videoPanel: { marginTop: 12 },
  videoStrip: { gap: 8, paddingVertical: 4 },
  videoPlayerCard: { width: 260, height: 160, borderRadius: 8, overflow: 'hidden', backgroundColor: '#102a43', borderWidth: 1, borderColor: '#cbd5e1' },
  videoChip: { width: 150, borderRadius: 8, borderWidth: 1, borderColor: '#cbd5e1', backgroundColor: '#f8fafc', padding: 10 },
  videoChipTitle: { color: '#102a43', fontWeight: '900' },
  videoChipText: { color: '#62748a', fontSize: 11, marginTop: 4 },
  ladderPanel: { marginTop: 16, borderRadius: 8, borderWidth: 1, borderColor: '#dbe7e4', backgroundColor: '#ffffff', padding: 14 },
  ladderTable: { minWidth: 760, marginTop: 12, borderRadius: 8, overflow: 'hidden', borderWidth: 1, borderColor: '#dbe7e4' },
  ladderRow: { flexDirection: 'row', minHeight: 38, alignItems: 'center', borderBottomWidth: 1, borderBottomColor: '#e2e8f0', backgroundColor: '#ffffff' },
  ladderHeader: { backgroundColor: '#102a43' },
  ladderRowActive: { backgroundColor: '#d9f6ee' },
  ladderHeadCell: { width: 108, color: '#ffffff', fontWeight: '900', paddingHorizontal: 10, fontSize: 12 },
  ladderCell: { width: 108, color: '#334e68', fontWeight: '800', paddingHorizontal: 10, fontSize: 12 },
  ladderCellStrong: { width: 108, color: '#0f766e', fontWeight: '900', paddingHorizontal: 10, fontSize: 12 },
  descriptionPreview: { marginTop: 14, backgroundColor: '#f8fafc', borderWidth: 1, borderColor: '#dbe7e4', borderRadius: 8, padding: 14 },
  descriptionImages: { gap: 10, paddingBottom: 12 },
  descriptionImage: { width: 132, height: 132, borderRadius: 8, backgroundColor: '#effaf7' },
  pagination: { gap: 8, alignItems: 'center', paddingVertical: 12 },
  pageGroup: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  pageButton: { minWidth: 38, minHeight: 34, borderRadius: 8, backgroundColor: '#ffffff', borderWidth: 1, borderColor: '#cbd5e1', alignItems: 'center', justifyContent: 'center', paddingHorizontal: 8 },
  pageButtonActive: { backgroundColor: '#0f766e', borderColor: '#0f766e' },
  pageText: { color: '#334e68', fontWeight: '900' },
  pageTextActive: { color: '#ffffff' },
  pageEllipsis: { color: '#62748a', fontWeight: '900' },
  segmentWrap: { marginTop: 16 },
  segmentRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  segmentButton: { minHeight: 36, minWidth: 58, borderRadius: 8, borderWidth: 1, borderColor: '#cbd5e1', alignItems: 'center', justifyContent: 'center', backgroundColor: '#f8fafc' },
  segmentButtonActive: { backgroundColor: '#0f766e', borderColor: '#0f766e' },
  segmentText: { color: '#334e68', fontWeight: '900' },
  segmentTextActive: { color: '#ffffff' },
  notice: { marginTop: 14, borderRadius: 8, borderWidth: 1, borderColor: '#facc15', backgroundColor: '#fffbeb', padding: 14 },
  noticeTitle: { color: '#854d0e', fontWeight: '900', marginBottom: 6 },
  noticeItem: { color: '#713f12', lineHeight: 20, fontWeight: '700' },
  goodLine: { color: '#0f766e', fontWeight: '800', marginTop: 6 },
  badLine: { color: '#991b1b', fontWeight: '800', marginTop: 6 },
  empty: { alignItems: 'center', justifyContent: 'center', minHeight: 240, backgroundColor: '#ffffff', borderRadius: 8, borderWidth: 1, borderColor: '#d5e7e2', padding: 24, marginTop: 14 },
  emptyTitle: { color: '#102a43', fontWeight: '900', fontSize: 20, marginTop: 10 },
  emptyDetail: { color: '#62748a', textAlign: 'center', lineHeight: 22, marginTop: 8, maxWidth: 620 },
  twoColumn: { flexDirection: 'row', flexWrap: 'wrap', gap: 14 },
  column: { flexGrow: 1, flexBasis: 320 },
  queueImage: { width: '100%', height: 160, borderRadius: 8, backgroundColor: '#effaf7', marginBottom: 10 },
  checkItem: { flexDirection: 'row', alignItems: 'center', gap: 9, minHeight: 34 },
  checkText: { color: '#334e68', fontWeight: '700', flex: 1 },
  ruleRow: { borderTopWidth: 1, borderTopColor: '#e2e8f0', paddingTop: 12, marginTop: 12 },
  ruleSignal: { color: '#102a43', fontWeight: '900' },
  ruleAction: { color: '#334e68', lineHeight: 21, marginTop: 4 },
});
