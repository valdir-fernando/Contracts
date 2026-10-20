<?php

namespace Tests\Feature;

use App\Http\Middleware\TrimInputStrings;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class TrimInputStringsTest extends TestCase
{
    public function test_query_and_nested_input_are_trimmed_including_passwords_without_changing_internal_spaces(): void
    {
        $request = Request::create('/search?q=%20busca%20', 'POST', [
            'name' => ' Nome  completo ',
            'password' => ' Senha123 ',
            'current_password' => ' Atual123 ',
            'password_confirmation' => ' Senha123 ',
            'items' => [['description' => ' Produto ', 'quantity' => 2]],
        ]);

        (new TrimInputStrings)->handle($request, function (Request $request): Response {
            $this->assertSame('busca', $request->query('q'));
            $this->assertSame('Nome  completo', $request->input('name'));
            $this->assertSame('Senha123', $request->input('password'));
            $this->assertSame('Atual123', $request->input('current_password'));
            $this->assertSame('Senha123', $request->input('password_confirmation'));
            $this->assertSame([['description' => 'Produto', 'quantity' => 2]], $request->input('items'));

            return new Response;
        });
    }
}
