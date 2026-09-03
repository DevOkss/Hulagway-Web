<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, ClipboardList, LayoutGrid, MapPinned, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage();
const role = computed(() => (page.props.auth as any)?.user?.role ?? '');
const isOfficer = computed(() => role.value === 'officer');
const isCoordinator = computed(() => role.value === 'coordinator');
const isLgu = computed(() => role.value === 'lgu');
const isMayor = computed(() => role.value === 'mayor');
const isFieldPersonnel = computed(() => role.value === 'field_personnel');

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];

    // LGU, Mayor and guests only get the map (aggregated public view with household + extensions)
    if (isLgu.value || isMayor.value) {
        return [{ title: 'Community Map', href: '/map', icon: MapPinned }];
    }

    items.push({ title: 'Dashboard', href: '/dashboard', icon: LayoutGrid });

    if (!isFieldPersonnel.value) {
        items.push({ title: 'Community Map', href: '/map', icon: MapPinned });
    }

    if (isOfficer.value) {
        items.push({
            title: 'Surveys',
            href: '/surveys',
            icon: ClipboardList,
        });
    }

    if (isOfficer.value || isCoordinator.value) {
        items.push({
            title: 'Extension Activities',
            href: '/extensions',
            icon: BarChart3,
        });
    }

    if (isOfficer.value) {
        items.push({
            title: 'User Accounts',
            href: '/users',
            icon: Users,
        });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="floating">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
