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
  import MaintenanceOverlay from './lib/components/MaintenanceOverlay.svelte';
  import { badgeForRoute } from './lib/dev/page-badges';
  import LazyView from './lib/components/LazyView.svelte';
  import { views } from './lib/views-loaders';
  import Login from './views/Login.svelte';
  import Register from './views/Register.svelte';
  import ForgotPassword from './views/ForgotPassword.svelte';
  import ResetPassword from './views/ResetPassword.svelte';
  import Legal from './views/Legal.svelte';
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
  const isSettingsRoute = $derived(route === '/settings' || route.startsWith('/settings/'));
  const devBadge = $derived(
    import.meta.env.DEV && !isSettingsRoute ? badgeForRoute(route) : null
  );
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
  const warehouseMatch = $derived(matchRoute('/stocks/warehouses/:id', route));
  const warehouseEditMatch = $derived(matchRoute('/stocks/warehouses/:id/edit', route));
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
      <LazyView load={views.requests} />
    {:else if route === '/requests/new'}
      <LazyView load={views.requestNew} />
    {:else if route === '/cart'}
      <LazyView load={views.cart} />
    {:else if requestMatch}
      {#key requestMatch.id}
        <LazyView load={views.requestCard} props={{ id: Number(requestMatch.id) }} />
      {/key}
    {:else if (route === '/clients' || route === '/clients/new' || clientMatch) && (!appSettings.clientsEnabled || auth.level < 10 || !(auth.can('clients.view.all') || auth.can('clients.view.own')))}
      <NotFound />
    {:else if route === '/clients'}
      <LazyView load={views.clients} />
    {:else if route === '/clients/new'}
      {#if auth.level >= 10}
        <LazyView load={views.clientNew} />
      {:else}
        <NotFound />
      {/if}
    {:else if clientMatch}
      {#key clientMatch.id}
        <LazyView load={views.clientCard} props={{ id: Number(clientMatch.id) }} />
      {/key}
    {:else if route === '/staff/substitutions/new'}
      {#if auth.can('requests.view.all') && appSettings.substitutionsEnabled}
        <LazyView load={views.substitutionNew} />
      {:else}
        <NotFound />
      {/if}
    {:else if substitutionEditMatch}
      {#if auth.can('requests.view.all') && appSettings.substitutionsEnabled}
        <LazyView load={views.substitutionEdit} props={{ id: Number(substitutionEditMatch.id) }} />
      {:else}
        <NotFound />
      {/if}
    {:else if (route === '/staff' || route === '/staff/new' || staffMatch) && (!appSettings.staffEnabled || !auth.can('users.manage'))}
      <NotFound />
    {:else if route === '/staff'}
      {#key route}<LazyView load={views.staff} props={{ initialTab: 'staff' }} />{/key}
    {:else if route === '/staff/new'}
      {#if auth.can('users.manage') && !appSettings.demoMode}
        <LazyView load={views.staffNew} />
      {:else}
        <NotFound />
      {/if}
    {:else if staffMatch}
      {#key staffMatch.id}
        <LazyView load={views.staffCard} props={{ id: Number(staffMatch.id) }} />
      {/key}
    {:else if route === '/chat' && (!appSettings.chatEnabled || !auth.can('chat.use'))}
      <NotFound />
    {:else if route === '/chat'}
      <LazyView load={views.chat} />
    {:else if route === '/stocks'}
      <LazyView load={views.stocks} />
    {:else if (route === '/stocks/groups' || route === '/stocks/groups/new' || groupEditMatch) && (!auth.can('settings.manage') || !appSettings.stockEditEnabled)}
      <NotFound />
    {:else if route === '/stocks/groups'}
      <LazyView load={views.stockGroups} />
    {:else if route === '/stocks/groups/new'}
      <LazyView load={views.stockGroupEdit} props={{ id: 0 }} />
    {:else if groupEditMatch}
      <LazyView load={views.stockGroupEdit} props={{ id: Number(groupEditMatch.id) }} />
    {:else if (route === '/stocks/types' || route === '/stocks/types/new' || typeEditMatch) && (!auth.can('settings.manage') || !appSettings.stockEditEnabled)}
      <NotFound />
    {:else if route === '/stocks/types'}
      <LazyView load={views.stockTypes} />
    {:else if route === '/stocks/types/new'}
      <LazyView load={views.stockTypeEdit} props={{ id: 0 }} />
    {:else if typeEditMatch}
      <LazyView load={views.stockTypeEdit} props={{ id: Number(typeEditMatch.id) }} />
    {:else if (route === '/stocks/price-types' || route === '/stocks/price-types/new' || priceTypeEditMatch) && (!auth.can('settings.manage') || !appSettings.stockEditEnabled)}
      <NotFound />
    {:else if route === '/stocks/price-types'}
      <LazyView load={views.priceTypes} />
    {:else if route === '/stocks/price-types/new'}
      <LazyView load={views.priceTypeEdit} props={{ id: 0 }} />
    {:else if priceTypeEditMatch}
      <LazyView load={views.priceTypeEdit} props={{ id: Number(priceTypeEditMatch.id) }} />
    {:else if route === '/stocks/warehouses/new' && (!appSettings.warehousesEnabled || !auth.can('stocks.warehouses.create'))}
      <NotFound />
    {:else if (route === '/stocks/warehouses' || warehouseEditMatch || warehouseMatch) && (!appSettings.warehousesEnabled || !(auth.can('stocks.warehouses.create') || auth.can('stocks.warehouses.edit') || auth.can('stocks.warehouses.delete') || auth.can('stocks.warehouses.access')))}
      <NotFound />
    {:else if route === '/stocks/warehouses'}
      <LazyView load={views.warehouses} />
    {:else if route === '/stocks/warehouses/new'}
      <LazyView load={views.warehouseCard} props={{ id: 0 }} />
    {:else if warehouseEditMatch}
      <LazyView load={views.warehouseCard} props={{ id: Number(warehouseEditMatch.id) }} />
    {:else if warehouseMatch}
      <LazyView load={views.warehouseView} props={{ id: Number(warehouseMatch.id) }} />
    {:else if (itemNewMatch || itemEditMatch) && !appSettings.stockEditEnabled}
      <NotFound />
    {:else if itemNewMatch}
      {#key 'new'}
        <LazyView load={views.itemEdit} props={{ id: 0 }} />
      {/key}
    {:else if itemEditMatch}
      {#key itemEditMatch.id}
        <LazyView load={views.itemEdit} props={{ id: Number(itemEditMatch.id) }} />
      {/key}
    {:else if itemCardMatch}
      {#key itemCardMatch.id}
        <LazyView load={views.itemCard} props={{ id: Number(itemCardMatch.id) }} />
      {/key}
    {:else if route === '/reports'}
      <LazyView load={views.reports} />
    {:else if route === '/substitutions'}
      {#key route}<LazyView load={views.staff} props={{ initialTab: 'substitutions' }} />{/key}
    {:else if route === '/notifications' && (!appSettings.notificationsEnabled || !auth.can('notifications.use'))}
      <NotFound />
    {:else if route === '/notifications'}
      <LazyView load={views.notifications} />
    {:else if isSettingsRoute && !auth.can('settings.manage') && !auth.can('mail.manage') && !auth.can('mail.send')}
      <NotFound />
    {:else if isSettingsRoute}
      <LazyView load={views.settings} />
    {:else if userMatch}
      <LazyView load={views.managerProfile} props={{ id: Number(userMatch.id) }} />
    {:else if legalMatch}
      <Legal code={legalMatch.code} />
    {:else if route === '/admin/audit'}
      {#if auth.level >= 90}
        <LazyView load={views.audit} />
      {:else}
        <NotFound />
      {/if}
    {:else if route === '/my-reports'}
      <LazyView load={views.myReports} />
    {:else if route === '/profile'}
      <LazyView load={views.profile} />
    {:else if route === '/about'}
      <LazyView load={views.about} />
    {:else}
      <NotFound />
    {/if}
  </AppShell>
{/if}

<CookieNotice />

<MaintenanceOverlay />

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
