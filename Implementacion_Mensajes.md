# Implementación Detallada: Sistema de Mensajería (Revisado)

Este documento proporciona una guía técnica completa y autocontenida para desarrollar el **Sistema de Mensajería** interno del juego, utilizando una arquitectura de layouts anidados en el App Router.

---

### **1. Estructura de Rutas**

-   **`src/app/messages/layout.tsx`**: Layout principal con la lista de mensajes.
-   **`src/app/messages/page.tsx`**: Página por defecto, visible cuando no hay un mensaje seleccionado.
-   **`src/app/messages/[messageId]/page.tsx`**: Vista para leer un mensaje.
-   **`src/app/messages/new/page.tsx`**: Vista para componer un mensaje.

---

### **2. Objetivo de la Página**

Crear un sistema de mensajería que permita a los jugadores comunicarse de forma privada, incluyendo leer, escribir y gestionar sus mensajes.

---

### **3. Tablas y Campos de Base de Datos Utilizados**

-   **`Message`**:
    -   `id`: Identificador único.
    -   `senderId`, `recipientId`: IDs para las relaciones con el remitente y destinatario.
    -   `subject`, `body`: Contenido del mensaje.
    -   `isRead`: Estado de lectura.
    -   `deletedBySender`, `deletedByRecipient`: Banderas para borrado suave (soft delete).
-   **`User`**:
    -   `id`, `username`: Para identificar y mostrar los nombres de remitente/destinatario.

---

### **4. Lógica de Obtención de Datos (Queries)**

La lógica de datos se encapsula en funciones de consulta específicas.

**`src/lib/features/messages/queries.ts`**:

```typescript
import { prisma } from "@/lib/core/prisma";
import { cache } from 'react';

// Obtiene la lista de resúmenes de mensajes para la barra lateral
export const getMessageList = cache(async (userId: string) => {
  return await prisma.message.findMany({
    where: { recipientId: userId, deletedByRecipient: false },
    select: { id: true, subject: true, isRead: true, sentAt: true, sender: { select: { username: true } } },
    orderBy: { sentAt: 'desc' },
  });
});

// Obtiene el contenido completo de un solo mensaje
export const getMessageById = cache(async (messageId: string, userId: string) => {
  return await prisma.message.findFirst({
    where: { id: messageId, recipientId: userId }, // Seguridad: solo el destinatario puede leer
    include: { sender: { select: { username: true } } },
  });
});
```

**`src/app/messages/layout.tsx`**:

```tsx
// ... (imports)
import { getMessageList } from "@/lib/features/messages/queries";
import { MessageList } from "@/components/features/messages/MessageList";

export default async function MessagesLayout({ children }) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) redirect("/login");

  const messages = await getMessageList(session.user.id);

  return (
    <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
      <aside className="md:col-span-1">
        <h2 className="text-2xl font-bold">Bandeja de Entrada</h2>
        <MessageList messages={messages} />
      </aside>
      <main className="md:col-span-3">{children}</main>
    </div>
  );
}
```

**`src/app/messages/[messageId]/page.tsx`**:

```tsx
// ... (imports)
import { getMessageById } from "@/lib/features/messages/queries";
import { markMessageAsRead } from "@/lib/features/messages/actions";
import { MessageView } from "@/components/features/messages/MessageView";

export default async function MessageViewPage({ params }) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) redirect("/login");

  const message = await getMessageById(params.messageId, session.user.id);
  if (!message) return <div>Mensaje no encontrado.</div>;

  // Marcar como leído si es necesario (la acción se encarga de la lógica)
  if (!message.isRead) {
    await markMessageAsRead(message.id);
  }

  return <MessageView message={message} />;
}
```

---

### **5. Desglose de Componentes**

#### **`MessageList` (Client Component)**
-   **Ruta:** `src/components/features/messages/MessageList.tsx`
-   **Propósito:** Muestra la lista de mensajes y permite la navegación.
-   **Lógica:** Es un **Client Component** para usar el hook `useParams` y el componente `<Link>`. Resalta el mensaje activo (`params.messageId`) y aplica estilos a los no leídos (`isRead: false`).

#### **`ComposeMessageForm` (Client Component)**
-   **Ruta:** `src/components/features/messages/ComposeMessageForm.tsx`
-   **Propósito:** Formulario para enviar mensajes.
-   **Lógica:** Formulario con estado local para los campos. El botón "Enviar" llama a `sendMessageAction` con `useTransition`.

---

### **6. Lógica de Mutación de Datos (Server Actions)**

**`src/lib/features/messages/actions.ts`**:

```typescript
"use server";
import { prisma } from "@/lib/core/prisma";
import { authOptions } from "@/lib/core/auth";
import { getServerSession } from "next-auth/next";
import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";

export async function sendMessageAction(formData: FormData) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) return { error: "No autenticado" };

  const recipientUsername = formData.get("recipient") as string;
  // ... (obtener subject y body)

  const recipient = await prisma.user.findUnique({ where: { username: recipientUsername } });
  if (!recipient) return { error: "Destinatario no encontrado." };

  await prisma.message.create({
    data: {
      senderId: session.user.id,
      recipientId: recipient.id,
      subject: formData.get("subject") as string,
      body: formData.get("body") as string,
    },
  });

  revalidatePath('/messages'); // Revalida la lista de mensajes del destinatario (y del remitente, si se implementa "Enviados")
  redirect('/messages'); // Redirige al usuario a la bandeja de entrada
}

export async function deleteMessageAction(messageId: string) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) return { error: "No autenticado" };

  // Actualiza la bandera de borrado en lugar de eliminar el registro (soft delete)
  await prisma.message.update({
    where: {
      id: messageId,
      recipientId: session.user.id, // Seguridad: solo el destinatario puede borrarlo de su vista
    },
    data: { deletedByRecipient: true },
  });

  revalidatePath('/messages');
  redirect('/messages');
}

export async function markMessageAsRead(messageId: string) {
    // Esta acción es llamada por el servidor, por lo que no necesita validación de sesión aquí,
    // ya que la página que la llama ya lo hizo.
    await prisma.message.update({
        where: { id: messageId },
        data: { isRead: true },
    });
    // Revalida la ruta para que el estado "no leído" se actualice en la UI
    revalidatePath('/messages');
}
```
Este enfoque con layouts anidados, queries específicas y Server Actions crea un sistema de mensajería robusto, seguro y eficiente.
