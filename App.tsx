import { StatusBar } from 'expo-status-bar';
import {
  AlertCircle,
  Bot,
  CheckCircle2,
  ClipboardList,
  ExternalLink,
  Gauge,
  Image as ImageIcon,
  Loader2,
  PackageSearch,
  RefreshCcw,
  Settings,
  ShieldCheck,
  SlidersHorizontal,
  Store,
  Wand2,
} from 'lucide-react-native';
import { useEffect, useMemo, useState } from 'react';
import { Image as RNImage, Linking, Pressable, ScrollView, StyleSheet, Text, TextInput, View, useWindowDimensions } from 'react-native';

type ScreenKey = 'home' | 'search' | 'builder' | 'queue' | 'active' | 'optimizer' | 'rules' | 'settings';
type IconType = typeof Gauge;
type JsonValue = Record<string, unknown> | unknown[] | string | number | boolean | null;

interface CategoryOption {
  id: string;
  name: string;
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

function flattenCategories(value: unknown): CategoryOption[] {
  const found: CategoryOption[] = [];
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
      found.push({ id: String(id), name: String(name) });
    }
    Object.values(item).forEach(visit);
  };
  visit(value);
  return found.filter((category, index, all) => all.findIndex((entry) => entry.id === category.id) === index).slice(0, 80);
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
  return products.slice(0, 20);
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
  const visit = (node: unknown) => {
    if (Array.isArray(node)) return node.forEach(visit);
    if (!node || typeof node !== 'object') return;
    for (const [key, entry] of Object.entries(node as Record<string, unknown>)) {
      if (typeof entry === 'string' && (key.toLowerCase().includes('image') || /\.(jpg|jpeg|png|webp)(\?|$)/i.test(entry))) {
        if (entry.startsWith('http')) urls.add(entry);
      } else {
        visit(entry);
      }
    }
  };
  visit(value);
  return [...urls].slice(0, 20);
}

function textFromHtml(html: string): string {
  return html.replace(/<style[\s\S]*?<\/style>/gi, '').replace(/<script[\s\S]*?<\/script>/gi, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
}

export default function App() {
  const [active, setActive] = useState<ScreenKey>('home');
  const [message, setMessage] = useState<AppMessage>({ kind: 'idle', text: 'Ready.' });
  const [health, setHealth] = useState<JsonValue>(null);
  const [dashboard, setDashboard] = useState<JsonValue>(null);
  const [settingsStatus, setSettingsStatus] = useState<JsonValue>(null);
  const [rules, setRules] = useState<JsonValue>(null);
  const [logs, setLogs] = useState<JsonValue>(null);
  const [categories, setCategories] = useState<CategoryOption[]>([]);
  const [warehouses, setWarehouses] = useState<JsonValue>(null);
  const [searchResult, setSearchResult] = useState<JsonValue>(null);
  const [optimizerResult, setOptimizerResult] = useState<JsonValue>(null);
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

  const screenBody = useMemo(() => {
    switch (active) {
      case 'search':
        return <SearchScreen run={run} categories={categories} setCategories={setCategories} warehouses={warehouses} setWarehouses={setWarehouses} result={searchResult} setResult={setSearchResult} />;
      case 'builder':
        return <BuilderScreen />;
      case 'queue':
        return <QueueScreen />;
      case 'active':
        return <ActiveListingsScreen />;
      case 'optimizer':
        return <OptimizerScreen run={run} result={optimizerResult} setResult={setOptimizerResult} />;
      case 'rules':
        return <RulesScreen rules={rules} loadRules={loadRules} />;
      case 'settings':
        return <SettingsScreen status={settingsStatus} health={health} logs={logs} loadHealth={loadHealth} loadSettings={loadSettings} loadLogs={loadLogs} run={run} />;
      default:
        return <HomeScreen dashboard={dashboard} health={health} settingsStatus={settingsStatus} loadDashboard={loadDashboard} loadHealth={loadHealth} loadRules={loadRules} />;
    }
  }, [active, categories, dashboard, health, logs, optimizerResult, rules, searchResult, settingsStatus, warehouses]);

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
}) {
  const [keyword, setKeyword] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<CategoryOption | null>(null);
  const [countryCode, setCountryCode] = useState('US');
  const [minInventory, setMinInventory] = useState('5');
  const [minPrice, setMinPrice] = useState('');
  const [maxPrice, setMaxPrice] = useState('');
  const [pageSize, setPageSize] = useState(30);
  const [pageNumber, setPageNumber] = useState(1);
  const [selectedProduct, setSelectedProduct] = useState<CjProductCard | null>(null);
  const [productDetail, setProductDetail] = useState<JsonValue>(null);
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
            minPrice: minPrice ? Number(minPrice) : undefined,
            maxPrice: maxPrice ? Number(maxPrice) : undefined,
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

  return (
    <View>
      <Panel title="CJ Product Search">
        <View style={styles.formGrid}>
          <Input label="Keyword" value={keyword} onChangeText={setKeyword} placeholder="phone stand, dog bed, kitchen organizer" />
          <Input label="Warehouse country" value={countryCode} onChangeText={setCountryCode} placeholder="US, CN, GB" />
          <Input label="Minimum inventory" value={minInventory} onChangeText={setMinInventory} placeholder="5" keyboardType="numeric" />
          <Input label="Min price" value={minPrice} onChangeText={setMinPrice} placeholder="0" keyboardType="numeric" />
          <Input label="Max price" value={maxPrice} onChangeText={setMaxPrice} placeholder="50" keyboardType="numeric" />
        </View>
        <View style={styles.actions}>
          <Button label="Load Warehouses" icon={Store} onPress={loadWarehouses} secondary />
          <Button label="Search Products" icon={PackageSearch} onPress={() => search(1, pageSize)} />
        </View>
        <Picker title="CJ category picker" options={props.categories} selectedId={selectedCategory?.id} onSelect={setSelectedCategory} />
        <Segmented label="Products per page" values={[30, 40, 50, 100]} selected={pageSize} onSelect={(size) => search(1, size)} />
      </Panel>
      {products.length > 0 ? (
        <>
          <Pagination current={view.pageNumber || pageNumber} total={view.totalPages || 1} onPage={(page) => search(page, pageSize)} />
          <Text style={styles.resultSummary}>{view.totalRecords.toLocaleString()} products found</Text>
          <View style={styles.resultGrid}>
            {products.map((product) => (
              <ProductCard key={product.id} product={product} onPress={() => openProduct(product)} />
            ))}
          </View>
          <Pagination current={view.pageNumber || pageNumber} total={view.totalPages || 1} onPage={(page) => search(page, pageSize)} />
        </>
      ) : (
        <EmptyState title="No CJ results loaded" detail="Load categories, pick a category if needed, then run a real CJ search." />
      )}
      <ProductDetailPanel product={selectedProduct} detail={productDetail} />
    </View>
  );
}

function BuilderScreen() {
  return (
    <TwoColumn
      left={
        <Panel title="Listing Builder Inputs">
          {['CJ product detail and variants', 'CJ inventory by product, SKU, or variant', 'Freight quote by destination', 'eBay market comparison from first 60-75 title characters', 'Duplicate check by ID, SKU, title, image, and active eBay titles'].map((item) => (
            <ChecklistItem key={item} text={item} />
          ))}
        </Panel>
      }
      right={
        <Panel title="Draft Output">
          {['SEO title under eBay title limit', 'Buyer-focused description and bullets', 'Full item specifics extraction', 'Main image ranking with manual override', 'Profit calculation showing landed cost, fees, margin, and cap reason', 'Action preview before publish'].map((item) => (
            <ChecklistItem key={item} text={item} />
          ))}
        </Panel>
      }
    />
  );
}

function QueueScreen() {
  return <EmptyState title="Approval Queue" detail="Drafts created from real CJ products will show title, price, estimated profit, duplicate risk, image choice, and publish approval controls here." />;
}

function ActiveListingsScreen() {
  return <EmptyState title="Active Listings" detail="When eBay inventory/listing sync is connected, listings will show price, views, clicks, sales, stock, cost changes, competitor shifts, and quick revision actions." />;
}

function OptimizerScreen(props: {
  run: <T>(loadingText: string, successText: string, work: () => Promise<T>, onSuccess?: (data: T) => void) => Promise<T | undefined>;
  result: JsonValue;
  setResult: (value: JsonValue) => void;
}) {
  const [days, setDays] = useState('30');
  const [limit, setLimit] = useState('25');
  const scan = () =>
    props.run(
      'Fetching eBay listings, traffic metrics, and marketer recommendations...',
      'Autonomous optimization scan finished.',
      () =>
        api<JsonValue>(`/optimizer/scan?days=${encodeURIComponent(days || '30')}&limit=${encodeURIComponent(limit || '25')}`),
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
        </View>
      </Panel>
      <JsonPanel title="Autonomous scan result" data={props.result} />
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
      <JsonPanel title="Raw rules config" data={rules} />
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
      <JsonPanel title="Environment credential status" data={props.status} />
      <JsonPanel title="Integration health" data={props.health} />
      <JsonPanel title="Logs" data={props.logs} collapsed />
    </View>
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

function JsonPanel({ title, data, collapsed }: { title: string; data: JsonValue; collapsed?: boolean }) {
  if (!data && collapsed) return null;
  return (
    <View style={styles.jsonPanel}>
      <Text style={styles.panelTitle}>{title}</Text>
      {data ? <Text style={styles.codeText}>{JSON.stringify(data, null, 2).slice(0, 6000)}</Text> : <Text style={styles.muted}>No data loaded yet.</Text>}
    </View>
  );
}

function EmptyState({ title, detail }: { title: string; detail: string }) {
  return (
    <View style={styles.empty}>
      <Image color="#0f766e" size={36} />
      <Text style={styles.emptyTitle}>{title}</Text>
      <Text style={styles.emptyDetail}>{detail}</Text>
    </View>
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

function Button({ label, icon: Icon, secondary, onPress }: { label: string; icon: IconType; secondary?: boolean; onPress?: () => void }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.button, secondary && styles.secondaryButton, pressed && styles.buttonPressed]}>
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
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.pickerContent}>
        {options.map((option) => {
          const selected = option.id === selectedId;
          return (
            <Pressable key={option.id} onPress={() => onSelect(option)} style={[styles.pill, selected && styles.pillSelected]}>
              <Text style={[styles.pillText, selected && styles.pillTextSelected]}>{option.name}</Text>
              <Text style={[styles.pillSub, selected && styles.pillTextSelected]}>{option.id}</Text>
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
  app: { flex: 1, backgroundColor: '#e8f7f2' },
  shell: { flex: 1 },
  shellWide: { flexDirection: 'row' },
  sidebar: { backgroundColor: 'rgba(255,255,255,0.9)', borderRightWidth: 1, borderRightColor: '#d5e7e2', padding: 18, width: 270 },
  sidebarMobile: { width: '100%', borderRightWidth: 0, borderBottomWidth: 1, borderBottomColor: '#d5e7e2' },
  brandRow: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 18 },
  brand: { fontSize: 18, fontWeight: '800', color: '#102a43' },
  brandSub: { fontSize: 12, color: '#52606d', marginTop: 2 },
  mobileNavContent: { gap: 8 },
  navItem: { minHeight: 42, flexDirection: 'row', alignItems: 'center', gap: 9, paddingHorizontal: 12, borderRadius: 8, marginBottom: 6 },
  navItemActive: { backgroundColor: '#d9f6ee' },
  navText: { color: '#52606d', fontWeight: '700', fontSize: 14 },
  navTextActive: { color: '#0f766e' },
  content: { flex: 1 },
  contentInner: { padding: 20, gap: 14 },
  header: { minHeight: 116, backgroundColor: '#ffffff', borderRadius: 8, padding: 18, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 16, borderWidth: 1, borderColor: '#d5e7e2' },
  headerText: { flex: 1, minWidth: 260 },
  eyebrow: { color: '#0f766e', fontSize: 12, textTransform: 'uppercase', fontWeight: '800' },
  title: { color: '#102a43', fontSize: 32, fontWeight: '900', marginTop: 4 },
  headerCopy: { color: '#52606d', marginTop: 8, lineHeight: 21, maxWidth: 760 },
  headerBadge: { flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: '#effaf7', borderRadius: 8, paddingHorizontal: 12, paddingVertical: 10 },
  headerBadgeText: { color: '#0f766e', fontWeight: '800' },
  messageBar: { minHeight: 44, backgroundColor: '#ffffff', borderRadius: 8, borderWidth: 1, borderColor: '#d5e7e2', paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', gap: 9 },
  messageText: { fontWeight: '800', flex: 1 },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 12 },
  healthGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 14, marginTop: 14 },
  statusCard: { flexGrow: 1, flexBasis: 280, backgroundColor: '#ffffff', borderRadius: 8, padding: 16, borderWidth: 1, borderColor: '#d5e7e2' },
  cardTitle: { fontSize: 16, color: '#102a43', fontWeight: '800' },
  statusText: { marginTop: 10, color: '#0f766e', fontWeight: '900' },
  muted: { color: '#62748a', lineHeight: 20, marginTop: 6 },
  bodyText: { color: '#334e68', lineHeight: 22, fontWeight: '600' },
  panel: { backgroundColor: '#ffffff', borderRadius: 8, padding: 18, borderWidth: 1, borderColor: '#d5e7e2', marginTop: 14 },
  jsonPanel: { backgroundColor: '#ffffff', borderRadius: 8, padding: 18, borderWidth: 1, borderColor: '#d5e7e2', marginTop: 14 },
  panelTitle: { color: '#102a43', fontWeight: '900', fontSize: 18, marginBottom: 14 },
  codeText: { color: '#102a43', fontFamily: 'monospace', fontSize: 12, lineHeight: 18, backgroundColor: '#f8fafc', padding: 12, borderRadius: 8 },
  step: { flexDirection: 'row', alignItems: 'center', gap: 9, minHeight: 34 },
  stepText: { color: '#334e68', fontWeight: '700', flex: 1 },
  formGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  inputWrap: { flexGrow: 1, flexBasis: 220 },
  inputLabel: { color: '#334e68', fontWeight: '800', marginBottom: 7, fontSize: 13 },
  input: { minHeight: 44, borderRadius: 8, borderWidth: 1, borderColor: '#cbd5e1', paddingHorizontal: 12, backgroundColor: '#f8fafc', color: '#102a43' },
  button: { minHeight: 42, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, backgroundColor: '#0f766e', borderRadius: 8, paddingHorizontal: 14 },
  buttonPressed: { opacity: 0.78 },
  secondaryButton: { backgroundColor: '#effaf7', borderWidth: 1, borderColor: '#99f6e4' },
  buttonText: { color: '#ffffff', fontWeight: '900' },
  secondaryButtonText: { color: '#0f766e' },
  picker: { marginTop: 16 },
  pickerContent: { gap: 8, paddingVertical: 4 },
  pill: { minWidth: 150, borderRadius: 8, borderWidth: 1, borderColor: '#cbd5e1', backgroundColor: '#f8fafc', paddingHorizontal: 12, paddingVertical: 10 },
  pillSelected: { backgroundColor: '#0f766e', borderColor: '#0f766e' },
  pillText: { color: '#102a43', fontWeight: '800' },
  pillSub: { color: '#62748a', fontSize: 11, marginTop: 4 },
  pillTextSelected: { color: '#ffffff' },
  resultGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, marginTop: 14 },
  productCard: { flexGrow: 1, flexBasis: 260, backgroundColor: '#ffffff', borderRadius: 8, padding: 14, borderWidth: 1, borderColor: '#d5e7e2' },
  empty: { alignItems: 'center', justifyContent: 'center', minHeight: 240, backgroundColor: '#ffffff', borderRadius: 8, borderWidth: 1, borderColor: '#d5e7e2', padding: 24, marginTop: 14 },
  emptyTitle: { color: '#102a43', fontWeight: '900', fontSize: 20, marginTop: 10 },
  emptyDetail: { color: '#62748a', textAlign: 'center', lineHeight: 22, marginTop: 8, maxWidth: 620 },
  twoColumn: { flexDirection: 'row', flexWrap: 'wrap', gap: 14 },
  column: { flexGrow: 1, flexBasis: 320 },
  checkItem: { flexDirection: 'row', alignItems: 'center', gap: 9, minHeight: 34 },
  checkText: { color: '#334e68', fontWeight: '700', flex: 1 },
  ruleRow: { borderTopWidth: 1, borderTopColor: '#e2e8f0', paddingTop: 12, marginTop: 12 },
  ruleSignal: { color: '#102a43', fontWeight: '900' },
  ruleAction: { color: '#334e68', lineHeight: 21, marginTop: 4 },
});
