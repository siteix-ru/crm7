<script lang="ts">
  import { onMount } from 'svelte';
  import { auth } from './lib/stores/auth.svelte';
  import { appSettings } from './lib/stores/app-settings.svelte';
  import { filterPrefs } from './lib/stores/filter-prefs.svelte';
  import { matchRoute, router } from './lib/router.svelte';
  import Spinner from './lib/components/ui/Spinner.svelte';
  import CookieNotice from './lib/components/CookieNotice.svelte';
  import AppShell from './lib/components/layout/AppShell.svelte';
  import PageBadge from './lib/components/dev/PageBadge.svelte';
  import { badgeForRoute } from './lib/dev/page-badges';
  import Login from './views/Login.svelte';
  import Register from './views/Register.svelte';
  import ForgotPassword from './views/ForgotPassword.svelte';
  import ResetPassword from './views/ResetPassword.svelte';
  import Requests from './views/Requests.svelte';
  import RequestCard from './views/RequestCard.svelte';
  import RequestNew from './views/RequestNew.svelte';
  import Cart from './views/Cart.svelte';
  import Clients from './views/Clients.svelte';
  import ClientNew from './views/ClientNew.svelte';
  import ClientCard from './views/ClientCard.svelte';
  import Staff from './views/Staff.svelte';
  import StaffNew from './views/StaffNew.svelte';
  import StaffCard from './views/StaffCard.svelte';
  import SubstitutionNew from './views/SubstitutionNew.svelte';
  import SubstitutionEdit from './views/SubstitutionEdit.svelte';
  import Chat from './views/Chat.svelte';
  import Stocks from './views/Stocks.svelte';
  import StockGroups from './views/StockGroups.svelte';
  import StockGroupEdit from './views/StockGroupEdit.svelte';
  import StockTypes from './views/StockTypes.svelte';
  import StockTypeEdit from './views/StockTypeEdit.svelte';
  import PriceTypes from './views/PriceTypes.svelte';
  import PriceTypeEdit from './views/PriceTypeEdit.svelte';
  import Warehouses from './views/Warehouses.svelte';
  import WarehouseCard from './views/WarehouseCard.svelte';
  import WarehouseView from './views/WarehouseView.svelte';
  import ItemEdit from './views/ItemEdit.svelte';
  import ItemCard from './views/ItemCard.svelte';
  import AdminAudit from './views/AdminAudit.svelte';
  import Legal from './views/Legal.svelte';
  import ManagerProfile from './views/ManagerProfile.svelte';
  import Notifications from './views/Notifications.svelte';
  import Reports from './views/Reports.svelte';
  import Settings from './views/Settings.svelte';
  import Profile from './views/Profile.svelte';
  import MyReports from './views/MyReports.svelte';
  import About from './views/About.svelte';
  import NotFound from './views/NotFound.svelte';

  onMount(() => {
    void auth.bootstrap();
  });

  $effect(() => {
    if (!auth.isAuthenticated && appSettings.loadedFor !== null) {
      appSettings.reset();
    }
  });

  $effect(() => {
    if (auth.isAuthenticated && appSettings.loadedFor !== auth.user?.id) {
      void appSettings.load();
    }
  });

  $effect(() => {
    const userId = auth.user?.id ?? null;

    if (auth.isAuthenticated && userId !== null && filterPrefs.loadedFor !== userId) {
      void filterPrefs.load(userId);
    }
  });

  $effect(() => {
    if (auth.isAuthenticated && router.current.path === '/') {
      router.navigate('/requests', true);
    }
  });

  const route = $derived(router.current.path);
  const devBadge = $derived(import.meta.env.DEV ? badgeForRoute(route) : null);
  const requestMatch = $derived(matchRoute('/requests/:id', route));
  const clientMatch = $derived(matchRoute('/clients/:id', route));
  const staffMatch = $derived(matchRoute('/staff/:id', route));
  const substitutionEditMatch = $derived(matchRoute('/staff/substitutions/:id', route));
  const legalMatch = $derived(matchRoute('/legal/:code', route));
  const userMatch = $derived(matchRoute('/users/:id', route));
  const itemNewMatch = $derived(matchRoute('/stocks/items/new', route));
  const itemEditMatch = $derived(matchRoute('/stocks/items/:id/edit', route));
  const itemCardMatch = $derived(matchRoute('/stocks/items/:id', route));
  const groupEditMatch = $derived(matchRoute('/stocks/groups/:id', route));
  const typeEditMatch = $derived(matchRoute('/stocks/types/:id', route));
  const priceTypeEditMatch = $derived(matchRoute('/stocks/price-types/:id', route));
  const warehouseMatch = $derived(matchRoute('/warehouses/:id', route));
  const warehouseEditMatch = $derived(matchRoute('/warehouses/:id/edit', route));
</script>

{#if !auth.ready || (auth.isAuthenticated && (filterPrefs.loadedFor !== auth.user?.id || appSettings.loadedFor !== auth.user?.id))}
  <div class="boot">
    <Spinner size={28} />
  </div>
{:else if !auth.isAuthenticated || route === '/reset'}
  {#if legalMatch}
    <Legal code={legalMatch.code} />
  {:else if route === '/forgot'}
    <ForgotPassword />
  {:else if route === '/reset'}
    <ResetPassword />
  {:else if route === '/register'}
    <Register />
  {:else}
    <Login />
  {/if}
{:else}
  <AppShell>
    {#if route === '/' || route === '/requests'}
      <Requests />
    {:else if route === '/requests/new'}
      <RequestNew />
    {:else if route === '/cart'}
      <Cart />
    {:else if requestMatch}
      <RequestCard id={Number(requestMatch.id)} />
    {:else if route === '/clients'}
      <Clients />
    {:else if route === '/clients/new'}
      {#if auth.level >= 10}
        <ClientNew />
      {:else}
        <NotFound />
      {/if}
    {:else if clientMatch}
      {#key clientMatch.id}
        <ClientCard id={Number(clientMatch.id)} />
      {/key}
    {:else if route === '/staff/substitutions/new'}
      {#if auth.can('requests.view.all') && appSettings.substitutionsEnabled}
        <SubstitutionNew />
      {:else}
        <NotFound />
      {/if}
    {:else if substitutionEditMatch}
      {#if auth.can('requests.view.all') && appSettings.substitutionsEnabled}
        <SubstitutionEdit id={Number(substitutionEditMatch.id)} />
      {:else}
        <NotFound />
      {/if}
    {:else if (route === '/staff' || route === '/staff/new' || staffMatch) && !auth.can('users.manage')}
      <NotFound />
    {:else if route === '/staff'}
      {#key route}<Staff initialTab="staff" />{/key}
    {:else if route === '/staff/new'}
      {#if auth.can('users.manage') && !appSettings.demoMode}
        <StaffNew />
      {:else}
        <NotFound />
      {/if}
    {:else if staffMatch}
      {#key staffMatch.id}
        <StaffCard id={Number(staffMatch.id)} />
      {/key}
    {:else if route === '/chat'}
      <Chat />
    {:else if route === '/stocks'}
      <Stocks />
    {:else if (route === '/stocks/groups' || route === '/stocks/groups/new' || groupEditMatch) && !auth.can('settings.manage')}
      <NotFound />
    {:else if route === '/stocks/groups'}
      <StockGroups />
    {:else if route === '/stocks/groups/new'}
      <StockGroupEdit id={0} />
    {:else if groupEditMatch}
      <StockGroupEdit id={Number(groupEditMatch.id)} />
    {:else if (route === '/stocks/types' || route === '/stocks/types/new' || typeEditMatch) && !auth.can('settings.manage')}
      <NotFound />
    {:else if route === '/stocks/types'}
      <StockTypes />
    {:else if route === '/stocks/types/new'}
      <StockTypeEdit id={0} />
    {:else if typeEditMatch}
      <StockTypeEdit id={Number(typeEditMatch.id)} />
    {:else if (route === '/stocks/price-types' || route === '/stocks/price-types/new' || priceTypeEditMatch) && !auth.can('settings.manage')}
      <NotFound />
    {:else if route === '/stocks/price-types'}
      <PriceTypes />
    {:else if route === '/stocks/price-types/new'}
      <PriceTypeEdit id={0} />
    {:else if priceTypeEditMatch}
      <PriceTypeEdit id={Number(priceTypeEditMatch.id)} />
    {:else if route === '/warehouses'}
      <Warehouses />
    {:else if route === '/warehouses/new'}
      <WarehouseCard id={0} />
    {:else if warehouseEditMatch}
      <WarehouseCard id={Number(warehouseEditMatch.id)} />
    {:else if warehouseMatch}
      <WarehouseView id={Number(warehouseMatch.id)} />
    {:else if itemNewMatch}
      <ItemEdit id={0} />
    {:else if itemEditMatch}
      <ItemEdit id={Number(itemEditMatch.id)} />
    {:else if itemCardMatch}
      <ItemCard id={Number(itemCardMatch.id)} />
    {:else if route === '/reports'}
      <Reports />
    {:else if route === '/substitutions'}
      {#key route}<Staff initialTab="substitutions" />{/key}
    {:else if route === '/notifications'}
      <Notifications />
    {:else if route === '/settings'}
      <Settings />
    {:else if userMatch}
      <ManagerProfile id={Number(userMatch.id)} />
    {:else if legalMatch}
      <Legal code={legalMatch.code} />
    {:else if route === '/admin/audit'}
      {#if auth.level >= 90}
        <AdminAudit />
      {:else}
        <NotFound />
      {/if}
    {:else if route === '/my-reports'}
      <MyReports />
    {:else if route === '/profile'}
      <Profile />
    {:else if route === '/about'}
      <About />
    {:else}
      <NotFound />
    {/if}
  </AppShell>
{/if}

<CookieNotice />

{#if devBadge}
  <PageBadge id={devBadge.id} task={devBadge.task} />
{/if}

<style>
  .boot {
    min-height: 100vh;
    display: grid;
    place-items: center;
  }
</style>
