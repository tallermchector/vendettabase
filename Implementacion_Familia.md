# Implementación: Gestión de Familia (Alianza)

Este documento proporciona una guía técnica completa y autocontenida para desarrollar la página de **Gestión de Familia**, que corresponde a la antigua `familia.php`. Esta sección es el centro neurálgico para todas las actividades sociales y de alianzas en el juego.

---

### **1. Ruta del Archivo**

`app/family/page.tsx`

---

### **2. Objetivo de la Página**

El objetivo es crear una interfaz multifacética que se adapte al estado del jugador:
1.  **Si no es miembro:** Permitirle buscar y ver familias existentes, solicitar unirse o crear una nueva.
2.  **Si es miembro:** Mostrarle un panel de control con los miembros, mensajes internos y la jerarquía de la familia.
3.  **Si es líder (o tiene permisos):** Proporcionarle herramientas para gestionar miembros, solicitudes, rangos y la configuración de la familia.

---

### **3. Lógica Condicional y Obtención de Datos**

La página `page.tsx` será un **Server Component** que actuará como un enrutador, decidiendo qué vista mostrar basándose en el estado de membresía del usuario.

**Lógica de `app/family/page.tsx`:**

```tsx
import { getServerSession } from "next-auth/next";
import { authOptions } from "@/app/api/auth/[...nextauth]/route";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";

// Importar los diferentes "paneles" o vistas
import { FamilyDashboard } from "@/components/family/FamilyDashboard";
import { NoFamilyView } from "@/components/family/NoFamilyView";

export default async function FamilyPage() {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) {
    redirect("/login");
  }
  const userId = session.user.id;

  // 1. Verificar si el usuario ya pertenece a una familia
  const membership = await prisma.familyMembership.findUnique({
    where: { userId },
    include: {
      family: true, // Incluir los datos de la familia
      rank: true,   // Incluir los datos del rango del usuario
    },
  });

  if (membership) {
    // 2. Si es miembro, obtener todos los datos necesarios para el panel de control
    const familyData = await prisma.family.findUnique({
      where: { id: membership.familyId },
      include: {
        members: {
          include: {
            user: { select: { username: true, pointsTotal: true } },
            rank: { select: { name: true } },
          },
        },
        ranks: true,
        applications: { // Solo obtener si el usuario tiene permisos
          where: membership.rank.canAcceptMembers ? {} : { id: "never" }, // Truco para no obtener nada
          include: { user: { select: { username: true } } },
        },
        messages: {
          include: { user: { select: { username: true } } },
          orderBy: { createdAt: 'desc' },
          take: 50,
        },
      },
    });
    return <FamilyDashboard familyData={familyData} userRank={membership.rank} />;
  } else {
    // 3. Si no es miembro, obtener la lista de familias para mostrar
    const allFamilies = await prisma.family.findMany({
      select: { id: true, name: true, tag: true, _count: { select: { members: true } } },
    });
    return <NoFamilyView families={allFamilies} />;
  }
}
```

---

### **4. Desglose de Componentes**

#### **Vista para No Miembros (`NoFamilyView`)**
-   **`FamilyList` (Server Component):** Muestra una tabla con las familias (`id`, `name`, `tag`, `member_count`). Cada fila puede ser un enlace a una página pública de perfil de familia (`/family/[familyId]`).
-   **`ApplyToFamilyButton` (Client Component):** Un botón que podría abrir un modal para escribir y enviar una solicitud, llamando a la `applyToFamilyAction`.
-   **`CreateFamilyForm` (Client Component):** Un formulario simple (`"use client"`) con campos para nombre y etiqueta. El botón de envío llama a `createFamilyAction` y usa `useTransition` para el estado de carga.

#### **Vista para Miembros (`FamilyDashboard`)**
-   **`FamilyHeader` (Server Component):** Muestra el nombre, etiqueta y descripción de la familia.
-   **`MemberList` (Client Component):**
    -   Recibe la lista de miembros como prop.
    -   Es un **Client Component** porque los líderes verán botones de acción junto a cada miembro.
    -   Botones como "Expulsar", "Ascender", "Degradar" llaman a sus respectivas Server Actions (`kickMemberAction`, `changeRankAction`) y pasan el `membershipId` del miembro objetivo. Los botones se muestran condicionalmente según los permisos del `userRank`.
-   **`ApplicationList` (Client Component):**
    -   Recibe la lista de solicitudes.
    -   Muestra el nombre del solicitante y su mensaje.
    -   Botones "Aceptar" y "Rechazar" que llaman a `acceptApplicationAction` y `rejectApplicationAction`.
-   **`RankManager` (Client Component):**
    -   Interfaz compleja para que los líderes gestionen los rangos y permisos.
    -   Muestra los rangos actuales y sus permisos.
    -   Incluye formularios para editar un rango o crear uno nuevo, cada uno llamando a su propia Server Action.
-   **`FamilyChat` (Client Component):**
    -   Muestra los últimos mensajes y un `textarea` para enviar uno nuevo.
    -   El envío se gestiona con una Server Action (`postFamilyMessageAction`). Tras un envío exitoso, `revalidatePath('/family')` actualizará el chat.

---

### **5. Server Actions Relevantes**

Las acciones de familia son críticas y **deben verificar los permisos del usuario en cada ejecución**.

#### **`createFamilyAction(formData)`**
-   **Lógica:** En una transacción, crea la `Family`, crea los `FamilyRank` por defecto (ej. "Líder", "Miembro"), y crea la `FamilyMembership` para el `userId`, asignándole el rango de "Líder". Llama a `revalidatePath('/family')`.

#### **`acceptApplicationAction(applicationId)`**
-   **Lógica:**
    1.  Verificar que el usuario actual tiene el permiso `canAcceptMembers`.
    2.  En una transacción:
        a.  Leer la aplicación para obtener el `userId` y `familyId`.
        b.  Eliminar la `FamilyApplication`.
        c.  Crear una `FamilyMembership` para el nuevo usuario con el rango por defecto.
    3.  Llamar a `revalidatePath('/family')`.

#### **`kickMemberAction(targetMembershipId)`**
-   **Lógica:**
    1.  Verificar permisos del actor.
    2.  Leer la membresía objetivo para asegurarse de que el actor no está intentando expulsar a alguien de un rango superior o a sí mismo.
    3.  Eliminar el registro `FamilyMembership` con el `targetMembershipId`.
    4.  Llamar a `revalidatePath('/family')`.

#### **`changeRankAction(targetMembershipId, newRankId)`**
-   **Lógica:**
    1.  Verificar permisos del actor.
    2.  Validar que el `newRankId` pertenece a la misma familia.
    3.  Actualizar el `rankId` en el registro `FamilyMembership`.
    4.  Llamar a `revalidatePath('/family')`.

#### **`postFamilyMessageAction(message)`**
-   **Lógica:**
    1.  Verificar que el usuario es miembro de la familia.
    2.  Crear un nuevo registro `FamilyMessage` asociado al `userId` y `familyId`.
    3.  Llamar a `revalidatePath('/family')`.

Este diseño maneja la complejidad de los permisos y los diferentes estados del usuario, manteniendo la lógica de negocio segura en el servidor y proporcionando una interfaz reactiva.
