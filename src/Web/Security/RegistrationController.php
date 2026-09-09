<?php

declare(strict_types=1);

namespace App\Web\Security;

use App\Auth\Api\RegistrationInput;
use App\Auth\Entity\User;
use App\Auth\UserRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bare registration page (spec user-accounts, web path). Uses the same
 * RegistrationInput as POST /api/v1/auth/register.
 */
final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $input = new RegistrationInput();
        $form = $this->createForm(RegistrationFormType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hash = $this->hasherFactory->getPasswordHasher(User::class)->hash($input->password);
            $this->users->add(new User($input->email, $hash, new \DateTimeImmutable()));
            try {
                $this->em->flush();

                return $this->redirectToRoute('app_login');
            } catch (UniqueConstraintViolationException) {
                $form->get('email')->addError(new FormError('An account with this email already exists.'));
            }
        }

        return $this->render('security/register.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
