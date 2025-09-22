# Implementación Detallada: Gestión de Familia (Alianza) (Revisado)

Este documento proporciona una guía técnica completa y autocontenida para desarrollar la página de **Gestión de Familia**, que corresponde a la antigua `familia.php`.

---

### **1. Ruta del Archivo**

`src/app/family/page.tsx`

---

### **2. Objetivo de la Página**

Crear una interfaz multifacética que se adapte al estado del jugador: si no es miembro, permitirle buscar o crear familias; si es miembro, mostrar un panel de control; y si es líder, proporcionar herramientas de gestión.

---

### **3. Tablas y Campos de Base de Datos Utilizados**

-   **`Family`**: `id`, `name`, `tag`, `description`. El modelo central.
-   **`FamilyMembership`**: `id`, `userId`, `familyId`, `rankId`. Conecta a los usuarios con las familias y sus rangos.
-   **`FamilyRank`**: `id`, `name`, y todos los campos de permisos booleanos (ej. `canAcceptMembers`, `canKickMembers`).
-   **`FamilyApplication`**: `id`, `userId`, `familyId`, `text`. Para las solicitudes pendientes.
-   **`FamilyMessage`**: `id`, `message`, `createdAt`, `userId`. Para el chat interno.
-   **`User`**: `id`, `username`, `pointsTotal`. Para mostrar información de miembros y solicitantes.

---

### **4. Lógica de Obtención de Datos (Queries)**

La página principal (`page.tsx`) es un **Server Component** que actúa como enrutador lógico.

**`src/lib/features/family/queries.ts`**:

```typescript
import { prisma } from "@/lib/core/prisma";
import { cache } from 'react';

// Obtiene los datos de membresía del usuario actual
export const getUserFamilyMembership = cache(async (userId: string) => {
  return await prisma.familyMembership.findUnique({
    where: { userId },
    include: { rank: true }, // Incluir los permisos del rango
  });
});

// Obtiene todos los datos de una familia para el dashboard
export const getFamilyDashboardData = cache(async (familyId: string, userRank: FamilyRank) => {
  return await prisma.family.findUnique({
    where: { id: familyId },
    include: {
      members: { include: { user: { select: { username: true, pointsTotal: true } }, rank: true } },
      ranks: true,
      // Condicionalmente incluye las solicitudes si el usuario tiene permiso
      applications: userRank.canAcceptMembers ? { include: { user: { select: { username: true } } } } : false,
      messages: { include: { user: { select: { username: true } } }, take: 50, orderBy: { createdAt: 'desc' } },
    },
  });
});

// Obtiene una lista de todas las familias para el usuario no miembro
export const getAllFamilies = cache(async () => {
  return await prisma.family.findMany({
    select: { id: true, name: true, tag: true, _count: { select: { members: true } } },
  });
});
```

**`src/app/family/page.tsx`**:

```tsx
// ... (imports)
import { getUserFamilyMembership, getFamilyDashboardData, getAllFamilies } from "@/lib/features/family/queries";
import { FamilyDashboard } from "@/components/features/family/FamilyDashboard";
import { NoFamilyView } from "@/components/features/family/NoFamilyView";

export default async function FamilyPage() {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) redirect("/login");

  const membership = await getUserFamilyMembership(session.user.id);

  if (membership) {
    const familyData = await getFamilyDashboardData(membership.familyId, membership.rank);
    return <FamilyDashboard familyData={familyData} userRank={membership.rank} />;
  } else {
    const allFamilies = await getAllFamilies();
    return <NoFamilyView families={allFamilies} />;
  }
}
```

---

### **5. Desglose de Componentes**

Los componentes se dividen en dos vistas principales: `NoFamilyView` y `FamilyDashboard`.

#### **`NoFamilyView` (Client Component)**
-   **Ruta:** `src/components/features/family/NoFamilyView.tsx`
-   **Propósito:** Interfaz para usuarios sin familia.
-   **Lógica:** Muestra una lista de familias (`FamilyList`) y un formulario para crear una nueva (`CreateFamilyForm`), que llama a la `createFamilyAction`.

#### **`FamilyDashboard` (Client Component)**
-   **Ruta:** `src/components/features/family/FamilyDashboard.tsx`
-   **Propósito:** Panel de control para miembros de la familia.
-   **Lógica:** Utiliza un sistema de pestañas (`<Tabs>`) para separar las diferentes secciones: "Miembros", "Chat", "Gestión" (solo para líderes).

#### **`MemberList` (Client Component)**
-   **Ruta:** `src/components/features/family/MemberList.tsx`
-   **Propósito:** Muestra los miembros y las acciones de gestión.
-   **Lógica:** Muestra una lista de miembros. Si el `userRank` tiene permisos, muestra botones ("Expulsar", "Ascender") junto a cada miembro, que llaman a las Server Actions correspondientes.

---

### **6. Lógica de Mutación de Datos (Server Actions)**

Todas las acciones deben realizar una comprobación de permisos al principio.

**`src/lib/features/family/actions.ts`**:

```typescript
"use server";
import { prisma } from "@/lib/core/prisma";
import { authOptions } from "@/lib/core/auth";
import { getServerSession } from "next-auth/next";
import { revalidatePath } from "next/cache";

// Ejemplo: Aceptar una solicitud
export async function acceptApplicationAction(applicationId: string) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) return { error: "No autenticado" };

  try {
    await prisma.$transaction(async (tx) => {
      // 1. Obtener membresía del actor para verificar permisos
      const actorMembership = await tx.familyMembership.findUnique({
        where: { userId: session.user.id },
        include: { rank: true },
      });
      if (!actorMembership?.rank.canAcceptMembers) {
        throw new Error("Permiso denegado.");
      }

      // 2. Procesar la solicitud
      const application = await tx.familyApplication.findUnique({ where: { id: applicationId } });
      if (!application || application.familyId !== actorMembership.familyId) {
        throw new Error("Solicitud no válida.");
      }

      const defaultRank = await tx.familyRank.findFirst({ where: { familyId: application.familyId, isDefault: true } });
      if (!defaultRank) throw new Error("Rango por defecto no encontrado.");

      // 3. Crear membresía y borrar solicitud
      await tx.familyMembership.create({
        data: { userId: application.userId, familyId: application.familyId, rankId: defaultRank.id },
      });
      await tx.familyApplication.delete({ where: { id: applicationId } });
    });
  } catch (error) {
    return { error: error.message };
  }

  revalidatePath('/family');
  return { success: true };
}

// ... (Otras acciones como kickMemberAction, createFamilyAction, etc.)
```
Este diseño modular y basado en permisos asegura que la gestión de la familia sea segura y robusta.
